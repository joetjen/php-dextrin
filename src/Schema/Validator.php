<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNValueInterface;

/**
 * Checks a value's fields against a {@see Compiled} schema
 * (required fields and their types) -- shared by decode ({@see
 * self::materialize()}, which also produces a materialized result) and
 * encode-time validation ({@see self::validateForEncode()}/{@see
 * self::validateTreeForEncode()}). Both directions reuse the exact
 * same `resolveFields()`/`TypeExpr::matches()` field-checking; encode's
 * own values are wrapped in the same {@see Validated} marker decode
 * already uses, via `wrapAndCheck()`, so there's one type-checking
 * implementation, not two that could drift.
 *
 * **One genuine difference from a decode-only validator**: encode here
 * can *transform* a value, not just answer ok/error -- `resolveFields`'s
 * `$coerce` option (see {@see Coercion}) may replace a field's value
 * with its coerced form (a plain `float` becoming a `DXNRational` for a
 * `:rational` field, say), and that coerced value has to actually
 * reach the printer/binary encoder, not just pass a check and be
 * discarded. `wrapAndCheck()` therefore rebuilds every container it
 * walks from its (possibly-coerced) children rather than returning the
 * value unchanged.
 *
 * Enforcement is decode-time and fail-fast, with no lenient escape
 * hatch to get the materialized value anyway -- a violation is always
 * a thrown {@see SchemaValidationError}.
 */
final class Validator
{
    private function __construct()
    {
    }

    /**
     * `$struct`'s fields checked against `$compiled`: every declared
     * field, in the schema's own order, as `[name, value]` pairs. A
     * required field that is missing, or a value that does not match its
     * declared type, is an error; an optional field that is missing takes
     * its default, or stays absent when it has none -- DXN tells an absent
     * field from one written `nil`. What is built from the result is
     * {@see Reader}'s job. Never coerces.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public static function resolve(Compiled $compiled, DXNStruct $struct, Registry $registry): array
    {
        $given = self::toNameMap($compiled, $struct);
        $pairs = [];
        foreach ($compiled->fields as $field) {
            $value = self::resolveField($field, $given, $registry, false);
            if (!$value instanceof Absent) {
                $pairs[] = [$field->name, $value];
            }
        }
        return $pairs;
    }

    /** @return array<string, mixed> */
    private static function toNameMap(Compiled $compiled, DXNStruct $struct): array
    {
        if ($struct->isKeyed()) {
            $result = [];
            foreach ($struct->pairs() as [$k, $v]) {
                $result[$k] = $v;
            }
            return $result;
        }
        $names = $compiled->fieldNames();
        $values = $struct->values();
        if (count($names) !== count($values)) {
            throw new SchemaValidationError('expected ' . count($names) . ' positional field(s), got ' . count($values));
        }
        $result = [];
        foreach ($names as $i => $name) {
            $result[$name] = $values[$i];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $given
     * @return array<string, mixed>
     */
    private static function resolveFields(Compiled $compiled, array $given, Registry $registry, bool $coerce): array
    {
        $resolved = [];
        foreach ($compiled->fields as $field) {
            // An absent optional field still holds its position when a
            // value goes out, as null.
            $value = self::resolveField($field, $given, $registry, $coerce);
            $resolved[$field->name] = $value instanceof Absent ? null : $value;
        }
        return $resolved;
    }

    /**
     * The checked value of `$field`, or {@see Absent} for an optional
     * field that was not given and has no default.
     *
     * @param array<string, mixed> $given
     */
    private static function resolveField(Field $field, array $given, Registry $registry, bool $coerce): mixed
    {
        if (array_key_exists($field->name, $given)) {
            return self::checkType($field->name, $given[$field->name], $field->type, $registry, $coerce);
        }
        if ($field->default !== Field::NO_DEFAULT) {
            return $field->default;
        }
        if ($field->required) {
            throw new SchemaValidationError("missing required field \"{$field->name}\"");
        }
        return Absent::instance();
    }

    private static function checkType(string $name, mixed $value, TypeExpr $type, Registry $registry, bool $coerce): mixed
    {
        if ($type->matches($value, $registry)) {
            // Stripped here, not deferred to the top: `$value` is
            // about to be handed to *this* struct's own materializer
            // (decode) or stored as a checked field value (encode) --
            // neither should ever see an internal `Validated` wrapper
            // once it's done its one job.
            return Validated::strip($value);
        }

        if ($coerce) {
            $coerced = Coercion::tryCoerce($type, $value);
            if (!$coerced instanceof NotCoercible && $type->matches($coerced, $registry)) {
                return Validated::strip($coerced);
            }
        }

        throw new SchemaValidationError("field \"{$name}\" does not match its declared type");
    }

    // ---- encode-time validation -----------------------------------------------
    //
    // The mirror image of resolve(), for data on its way *out*:
    // `$value` here is ordinary PHP data about to be encoded -- a
    // DXNStruct (the one shape that round-trips to `%Name{...}` wire
    // syntax), a bare PHP array (this port's own `map`), or a real
    // application class instance -- and may come back transformed if
    // `$coerce` lets a field's value be coerced toward its declared
    // type; see this class's own doc for why.
    //
    // The one structural difference from decode: decode's nested
    // structs arrive *already* `Validated`-wrapped, bottom-up, as a
    // natural consequence of how decoding recurses. Encode has no such
    // built-in recursion to piggyback on, so wrapAndCheck() does it
    // explicitly -- walking `$value`, checking (and rebuilding, with
    // any coercions applied) every recognized struct, before
    // resolveFields() ever sees it.

    /**
     * Checks `$value` -- ordinary PHP data about to be encoded --
     * against `$compiled`. Returns the checked (and, if `$coerce`,
     * possibly coerced) value, {@see Validated}-wrapped.
     */
    public static function validateForEncode(Compiled $compiled, mixed $value, Registry $registry, bool $coerce = true): Validated
    {
        return self::checkCompiled($compiled, $value, $registry, $coerce);
    }

    /**
     * Walks `$value` looking for every {@see DXNStruct} or registered
     * application class instance anywhere in the tree and checks each
     * one against its own schema, if the registry has one --
     * regardless of what any enclosing field's declared type is,
     * recursing fully into whatever it finds. Returns the
     * (possibly-coerced) tree.
     */
    public static function validateTreeForEncode(mixed $value, Registry $registry, bool $coerce = true): mixed
    {
        return self::wrapAndCheck($value, $registry, $coerce);
    }

    private static function checkCompiled(Compiled $compiled, mixed $value, Registry $registry, bool $coerce): Validated
    {
        // An extra field never shadows a declared one (DXN.md §4.7), on
        // the way out as on the way in.
        if ($value instanceof DXNStruct) {
            $declared = array_flip($compiled->fieldNames());
            foreach ($value->extra as [$key, $unusedValue]) {
                if (is_string($key) && isset($declared[$key])) {
                    throw new SchemaValidationError("extra field \"{$key}\" shadows a declared field");
                }
            }
        }
        $given = self::toNameMapForEncode($compiled, $value);
        $wrappedGiven = self::wrapGiven($given, $registry, $coerce);
        $resolved = self::resolveFields($compiled, $wrappedGiven, $registry, $coerce);
        return new Validated($compiled->name ?? '', self::rebuildChecked($value, $compiled, $resolved));
    }

    /**
     * Rebuilds `$value` in its *original* shape (a `DXNStruct`, or a
     * real application class instance) with `$resolved`'s checked --
     * and, if coercion applied, coerced -- field values written back
     * in. Without this, `checkCompiled()` would hand the
     * printer/binary encoder a bare field map instead of something it
     * actually knows how to encode (a plain array has no name of its
     * own to recover `%Name{...}` wire syntax from) -- the encode-time
     * analog of why `materialize()`'s decode-side default already
     * produces a real, named result rather than discarding shape
     * information.
     *
     * A value that was neither of those (a bare PHP array, this port's
     * own `map`) is *promoted* to a keyed `DXNStruct` under
     * `$compiled`'s own name: having just checked it against that
     * specific schema, the natural encoded form is `%Name{...}`, not a
     * bare, nameless `%{...}` map with no record that a check ever
     * happened.
     *
     * **PHP-specific limitation**: rebuilding a real application class
     * instance writes coerced field values back onto a clone via
     * direct property assignment, which throws if that property is
     * `readonly` (PHP has no generic "reconstruct with these fields
     * changed" for readonly properties the way JS's always-mutable
     * objects allow) -- a genuinely PHP-specific constraint the JS
     * port doesn't share, not something this port works around.
     *
     * @param array<string, mixed> $resolved
     */
    private static function rebuildChecked(mixed $value, Compiled $compiled, array $resolved): mixed
    {
        if ($value instanceof DXNStruct) {
            if ($value->isKeyed()) {
                return $value->withPairs(array_map(
                    static fn (array $p): array => [$p[0], $resolved[$p[0]] ?? null],
                    $value->pairs(),
                ));
            }
            return $value->withValues(array_map(
                static fn (string $name): mixed => $resolved[$name] ?? null,
                $compiled->fieldNames(),
            ));
        }
        if (is_object($value) && !$value instanceof \Closure) {
            $rebuilt = clone $value;
            foreach ($resolved as $name => $v) {
                if (property_exists($rebuilt, $name)) {
                    $rebuilt->$name = $v;
                }
            }
            return $rebuilt;
        }
        return DXNStruct::keyed($compiled->name ?? '', array_map(
            static fn (string $name): array => [$name, $resolved[$name] ?? null],
            $compiled->fieldNames(),
        ));
    }

    /** @return array<string, mixed> */
    private static function toNameMapForEncode(Compiled $compiled, mixed $value): array
    {
        if ($value instanceof DXNStruct) {
            if ($value->isKeyed()) {
                $result = [];
                foreach ($value->pairs() as [$k, $v]) {
                    $result[$k] = $v;
                }
                return $result;
            }
            $names = $compiled->fieldNames();
            $values = $value->values();
            if (count($names) !== count($values)) {
                throw new SchemaValidationError('expected ' . count($names) . ' positional field(s), got ' . count($values));
            }
            $result = [];
            foreach ($names as $i => $name) {
                $result[$name] = $values[$i];
            }
            return $result;
        }
        if (is_array($value)) {
            // A bare PHP array is already this port's native `map`
            // representation -- unlike the JS port, no `Map`
            // key-stringification step is needed here at all.
            $result = [];
            foreach ($value as $k => $v) {
                $result[(string) $k] = $v;
            }
            return $result;
        }
        if (is_object($value) && !$value instanceof \Closure) {
            return get_object_vars($value);
        }
        throw new SchemaValidationError('expected a map/struct/object to validate against a schema, got ' . get_debug_type($value));
    }

    /**
     * `wrapAndCheck()` is the recursive core: for any value that's a
     * named struct with a registered schema, check it
     * (`checkCompiled()`) and wrap the result in `Validated`; otherwise
     * recurse into whatever container it is, reconstructing it from
     * the (possibly-coerced, possibly-wrapped) results so a
     * wrapped/coerced grandchild survives all the way up to whichever
     * ancestor's field type actually needs to see it.
     */
    private static function wrapAndCheck(mixed $value, Registry $registry, bool $coerce): mixed
    {
        $found = self::schemaNameFor($value, $registry);
        if ($found !== null) {
            [$name, $compiled] = $found;
            try {
                return self::checkCompiled($compiled, $value, $registry, $coerce);
            } catch (\Throwable $err) {
                throw new SchemaValidationError("struct \"{$name}\" violates its schema: " . $err->getMessage());
            }
        }
        return self::wrapChildren($value, $registry, $coerce);
    }

    /** @return array{0: string, 1: Compiled}|null */
    private static function schemaNameFor(mixed $value, Registry $registry): ?array
    {
        if ($value instanceof DXNStruct) {
            $found = $registry->fetchStructSchema($value->name);
            return $found !== null ? [$value->name, $found->compiled] : null;
        }
        if (is_object($value) && !$value instanceof \Closure && !$value instanceof DXNValueInterface) {
            $name = $registry->fetchSchemaNameForModule($value::class);
            if ($name === null) {
                return null;
            }
            $found = $registry->fetchStructSchema($name);
            return $found !== null ? [$name, $found->compiled] : null;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $given
     * @return array<string, mixed>
     */
    private static function wrapGiven(array $given, Registry $registry, bool $coerce): array
    {
        $result = [];
        foreach ($given as $k => $v) {
            $result[$k] = self::wrapAndCheck($v, $registry, $coerce);
        }
        return $result;
    }

    private static function wrapChildren(mixed $value, Registry $registry, bool $coerce): mixed
    {
        // Both DXN `map` and `array` are a bare PHP array (see the
        // project's own collections decision) -- one branch,
        // key-preserving, covers both, unlike the JS port's own
        // separate `Array`/`Map` handling.
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::wrapAndCheck($v, $registry, $coerce);
            }
            return $result;
        }
        if ($value instanceof DXNList) {
            return new DXNList(array_map(static fn (mixed $v): mixed => self::wrapAndCheck($v, $registry, $coerce), $value->items));
        }
        if ($value instanceof DXNTuple) {
            return new DXNTuple(array_map(static fn (mixed $v): mixed => self::wrapAndCheck($v, $registry, $coerce), $value->items));
        }
        if ($value instanceof DXNSet) {
            return new DXNSet(array_map(static fn (mixed $v): mixed => self::wrapAndCheck($v, $registry, $coerce), $value->items));
        }
        if ($value instanceof DXNSortedSet) {
            return DXNSortedSet::fromArray(array_map(static fn (mixed $v): mixed => self::wrapAndCheck($v, $registry, $coerce), $value->items));
        }
        if ($value instanceof DXNOrderedMap) {
            return new DXNOrderedMap(array_map(
                static fn (array $p): array => [$p[0], self::wrapAndCheck($p[1], $registry, $coerce)],
                $value->pairs,
            ));
        }
        if ($value instanceof DXNStruct) {
            return $value->isKeyed()
                ? $value->withPairs(array_map(
                    static fn (array $p): array => [$p[0], self::wrapAndCheck($p[1], $registry, $coerce)],
                    $value->pairs(),
                ))
                : $value->withValues(array_map(
                    static fn (mixed $v): mixed => self::wrapAndCheck($v, $registry, $coerce),
                    $value->values(),
                ));
        }
        if ($value instanceof DXNCustomTag) {
            return new DXNCustomTag($value->name, self::wrapAndCheck($value->value, $registry, $coerce));
        }
        // Every other DXN* leaf value (DXNChar, DXNSymbol, DXNKeyword,
        // DXNDecimal, DXNRational, DXNDate, DXNTime, DXNTimestamp,
        // DXNDateTime, DXNUuid, DXNUri, DXNBytes, DXNRegex, ...) is
        // already a complete, opaque DXN value with nothing
        // schema-checkable inside it -- passes through untouched.
        if ($value instanceof DXNValueInterface) {
            return $value;
        }
        // A genuine application object with no schema of its own --
        // still worth reaching into, in case something deeper does
        // have one. Same readonly-property caveat as rebuildChecked().
        if (is_object($value) && !$value instanceof \Closure) {
            $rebuilt = clone $value;
            foreach (get_object_vars($value) as $k => $v) {
                if (property_exists($rebuilt, $k)) {
                    $rebuilt->$k = self::wrapAndCheck($v, $registry, $coerce);
                }
            }
            return $rebuilt;
        }
        return $value;
    }
}
