<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Codegen;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Schema\Deprecations;
use JOetjen\Dextrin\Schema\TypeExpr;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNMap;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\Equality;

/**
 * The few functions code generated from a schema ({@see Php}) calls: one
 * per form a check takes (a shape, a list, a union, ...), ways to read a
 * struct's fields, to say what a value is in an error, to decode a
 * constant written as DXN text, and to write a value back as DXN. None of
 * them reads a schema; everything a schema says is in the generated code.
 *
 * **Deprecation warnings** (T698; DXNS.md §1b, as {@see Deprecations}
 * reports them): a value that goes through something deprecated is
 * *noted* while it is read, and the notes are settled when the outermost
 * read ends ({@see self::reading()}) --
 *
 * - so a variant tried and refused stays silent: {@see self::attempt()}
 *   drops what it noted;
 * - so `'error'` refuses the whole value, whichever check noted it;
 * - so a read that fails anyway reports nothing.
 *
 * The read's mode and notes live in this class for as long as it runs
 * (PHP runs one read at a time). "Once" is per generated class and
 * deprecated thing, numbered when the class is generated: there is no
 * registry to hold it.
 *
 * @phpstan-import-type Warning from Deprecations
 * @phpstan-type Spec array{0: string, 1: bool, 2: (\Closure(mixed): mixed)|null, 3: (\Closure(): mixed)|null}
 */
final class Runtime
{
    /** @var array{mode: string, notes: list<array{0: string, 1: int, 2: Warning}>}|null */
    private static ?array $reading = null;

    /** @var array<string, array<int, true>> which deprecated things have warned, by generated class */
    private static array $seen = [];

    private function __construct()
    {
    }

    // ---- reading, and deprecations ----------------------------------------------------

    /**
     * `$read()`, a read or a check, with deprecation warnings as
     * `$deprecations` says: `'once'` per generated class and deprecated
     * thing, `'every'` use, `'off'`, or `'error'`, which refuses the value
     * with the first (`schema/deprecated`). `$onDeprecated` receives each
     * warning in place of `trigger_error($text, E_USER_DEPRECATED)`. Inside
     * another read it only runs `$read`: the outermost read settles.
     *
     * @template T
     * @param string $deprecations `once`, `every`, `off` or `error`
     * @param callable|null $onDeprecated receives each warning
     * @param \Closure(): T $read the read
     * @return T what it answers
     * @throws InvalidData the read's refusal, or the deprecation `'error'` makes one
     * @throws \InvalidArgumentException for an unknown `$deprecations`
     */
    public static function reading(string $deprecations, ?callable $onDeprecated, \Closure $read): mixed
    {
        if (self::$reading !== null) {
            return $read();
        }
        if (!in_array($deprecations, Deprecations::MODES, true)) {
            throw new \InvalidArgumentException("deprecations: is once, every, off or error, got {$deprecations}");
        }
        self::$reading = ['mode' => $deprecations, 'notes' => []];
        try {
            $result = $read();
            $notes = self::notes();
        } finally {
            self::$reading = null;
        }
        self::settle($notes, $deprecations, $onDeprecated === null ? null : \Closure::fromCallable($onDeprecated));
        return $result;
    }

    /**
     * A value went through something deprecated: `$slot` of the generated
     * class `$class`, named `$name` at `$version` (either `null` where it
     * has none), for `$why`. Outside a read it is settled at once, as
     * `'once'`, with `trigger_error()`.
     *
     * @param string $class the generated class, whose "once" state it is
     * @param int $slot which of its deprecated things
     * @param string|null $name what is deprecated, when it has a name
     * @param int|null $version its version, when it has one
     * @param string|null $why the reason, `null` for `deprecated: true`
     * @throws InvalidData outside a read, never: `'once'` only warns
     */
    public static function deprecated(string $class, int $slot, ?string $name, ?int $version, ?string $why): void
    {
        $note = [$class, $slot, Deprecations::warning($name, $version, $why)];
        if (self::$reading === null) {
            self::settle([$note], 'once', null);
            return;
        }
        if (self::$reading['mode'] !== 'off') {
            self::$reading['notes'][] = $note;
        }
    }

    /**
     * `$value` went through an enum whose deprecated values are `$values`,
     * each `[literal, slot, why]`: noted when it is one of them.
     *
     * @param string $class the generated class
     * @param mixed $value the value
     * @param list<array{0: mixed, 1: int, 2: ?string}> $values the deprecated literals
     */
    public static function deprecatedValue(string $class, mixed $value, array $values): void
    {
        foreach ($values as [$literal, $slot, $why]) {
            if (Equality::dxnEquals(self::plain($literal), self::plain($value))) {
                self::deprecated($class, $slot, null, null, $why);
                return;
            }
        }
    }

    /**
     * `$check($value)`, a variant tried: what it noted is kept only when
     * it takes the value.
     *
     * @param \Closure(mixed): mixed $check the variant's check
     * @param mixed $value the value
     * @return mixed what the check answers
     * @throws InvalidData the variant's refusal
     */
    public static function attempt(\Closure $check, mixed $value): mixed
    {
        $before = self::notes();
        try {
            return $check($value);
        } catch (InvalidData $refused) {
            if (self::$reading !== null) {
                self::$reading['notes'] = $before;
            }
            throw $refused;
        }
    }

    /** @return list<array{0: string, 1: int, 2: Warning}> */
    private static function notes(): array
    {
        return self::$reading['notes'] ?? [];
    }

    /**
     * @param list<array{0: string, 1: int, 2: Warning}> $notes
     * @param \Closure(Warning): mixed|null $hear
     */
    private static function settle(array $notes, string $mode, ?\Closure $hear): void
    {
        if ($notes === []) {
            return;
        }
        if ($mode === 'error') {
            throw new InvalidData(Deprecations::text($notes[0][2]), [], Deprecations::CATEGORY);
        }
        foreach ($notes as [$class, $slot, $warning]) {
            if ($mode === 'every' || !isset(self::$seen[$class][$slot])) {
                self::$seen[$class][$slot] = true;
                if ($hear !== null) {
                    $hear($warning);
                } else {
                    trigger_error(Deprecations::text($warning), E_USER_DEPRECATED);
                }
            }
        }
    }

    /** An enum's literal as written may be a keyword; compared by its name. */
    private static function plain(mixed $value): mixed
    {
        return $value instanceof DXNKeyword ? new DXNKeyword($value->name) : $value;
    }

    // ---- structs ---------------------------------------------------------------------

    /**
     * A struct's fields by name: keyed as they are, positional by
     * `$names`, a schema's fields in order. Its extra fields are left out.
     *
     * @param DXNStruct $struct the struct as decoded
     * @param list<string> $names the fields of the version it was written with
     * @return array<string, mixed> the fields
     * @throws InvalidData a positional struct with another number of fields (`schema/field-count`)
     */
    public static function named(DXNStruct $struct, array $names): array
    {
        if ($struct->isKeyed()) {
            $fields = [];
            foreach ($struct->pairs() as [$name, $value]) {
                $fields[$name] = $value;
            }
            return $fields;
        }
        $values = $struct->values();
        if (count($values) !== count($names)) {
            throw new InvalidData('expected ' . count($names) . ' positional field(s), got ' . count($values), [], 'schema/field-count');
        }
        return array_combine($names, $values);
    }

    /**
     * A struct's or shape's fields checked as `$specs` say -- each
     * `[name, required, check, default]`, the check `null` for a field
     * that must be absent (`:never`), the default `null` for none -- and
     * answered by name, defaults filled. A field not in `$specs` is left
     * out.
     *
     * @param array<string, mixed> $fields the fields given, by name
     * @param list<Spec> $specs the fields declared
     * @return array<string, mixed> the fields, checked
     * @throws InvalidData the first field refused, named
     */
    public static function fields(array $fields, array $specs): array
    {
        $out = [];
        foreach ($specs as [$name, $required, $check, $default]) {
            if (array_key_exists($name, $fields)) {
                $out[$name] = self::checked($name, $check, $fields[$name]);
            } elseif ($default !== null) {
                $out[$name] = $default();
            } elseif ($required) {
                throw new InvalidData("missing required field {$name}", [], 'schema/missing-field');
            }
        }
        return $out;
    }

    /**
     * A closed schema's version (`- *`) takes no field it does not
     * declare: the first of `$fields` not among `$names` is refused, before
     * any field is checked.
     *
     * @param array<string, mixed> $fields the fields given, by name
     * @param list<string> $names the fields the version declares
     * @throws InvalidData naming the field (`schema/undeclared-field`)
     */
    public static function closed(array $fields, array $names): void
    {
        foreach (array_keys($fields) as $name) {
            if (!in_array((string) $name, $names, true)) {
                throw new InvalidData("field \"{$name}\" is not declared, and the schema takes no other", [], 'schema/undeclared-field');
            }
        }
    }

    /**
     * The fields given -- a validated object's properties by field name --
     * without those holding `null`, which are absent.
     *
     * @param array<int|string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function present(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $value) {
            if ($value !== null) {
                $out[(string) $name] = $value;
            }
        }
        return $out;
    }

    /**
     * A generated object's fields as a struct's pairs, written back as DXN
     * ({@see self::toDxn()}), those holding `null` left out.
     *
     * @param array<int|string, mixed> $fields by field name
     * @return list<array{0: string, 1: mixed}>
     */
    public static function pairs(array $fields): array
    {
        $pairs = [];
        foreach (self::present($fields) as $name => $value) {
            $pairs[] = [$name, self::toDxn($value)];
        }
        return $pairs;
    }

    /**
     * Fields of one version with `$from` moved to `$to`, where it is.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function move(array $fields, string $from, string $to): array
    {
        if (array_key_exists($from, $fields)) {
            $fields[$to] = $fields[$from];
            unset($fields[$from]);
        }
        return $fields;
    }

    /** @param (\Closure(mixed): mixed)|null $check */
    private static function checked(string $name, ?\Closure $check, mixed $value): mixed
    {
        if ($check === null) {
            throw (new InvalidData('no value is allowed here'))->under($name);
        }
        try {
            return $check($value);
        } catch (InvalidData $refused) {
            throw $refused->under($name);
        }
    }

    // ---- the forms -------------------------------------------------------------------

    /**
     * A value of the primitive `$name` ({@see TypeExpr::isPrimitive()}).
     *
     * @throws InvalidData when it is not one
     */
    public static function primitive(string $name, mixed $value): mixed
    {
        return TypeExpr::isPrimitive($name, $value) ? $value : throw InvalidData::expected($name, $value);
    }

    /**
     * A value meeting `$constraints`, a refine's, written as DXN and
     * decoded ({@see self::value()}); `$primitive` is what it refines.
     *
     * @throws InvalidData when one does not hold
     */
    public static function refine(mixed $value, mixed $constraints, ?string $primitive): mixed
    {
        if (!is_array($constraints)) {
            throw new \LogicException('a refine\'s constraints are a map');
        }
        /** @var array<string, mixed> $constraints */
        return TypeExpr::meetsConstraints($value, $constraints, $primitive)
            ? $value
            : throw new InvalidData(self::show($value) . ' does not meet ' . self::show($constraints));
    }

    /**
     * One of an enum's `$literals` ({@see TypeExpr::isEnumMember()}).
     *
     * @throws InvalidData when it is none of them
     */
    public static function enum(mixed $value, mixed $literals): mixed
    {
        $literals = is_array($literals) ? array_values($literals) : [];
        return TypeExpr::isEnumMember($literals, $value)
            ? $value
            : throw new InvalidData('expected one of ' . self::show($literals) . ', got ' . self::show($value));
    }

    /**
     * A list, every item checked: a new list of what the checks answer.
     *
     * @param \Closure(mixed): mixed $check
     * @throws InvalidData the first item refused, as `[n]`
     */
    public static function listOf(mixed $value, \Closure $check): DXNList
    {
        if (!$value instanceof DXNList) {
            throw InvalidData::expected('a list', $value);
        }
        $items = [];
        foreach ($value->items as $i => $item) {
            $items[] = self::checked("[{$i}]", $check, $item);
        }
        return new DXNList($items);
    }

    /**
     * A map, every key and value checked; its values as the checks answer
     * them, its keys as they are.
     *
     * @param \Closure(mixed): mixed $key
     * @param \Closure(mixed): mixed $item
     * @return array<int|string, mixed>|DXNMap
     * @throws InvalidData the first entry refused, under its key
     */
    public static function mapOf(mixed $value, \Closure $key, \Closure $item): array|DXNMap
    {
        if ($value instanceof DXNMap) {
            $pairs = [];
            foreach ($value->pairs() as [$k, $v]) {
                $shown = self::show($k);
                self::checked($shown, $key, $k);
                $pairs[] = [$k, self::checked($shown, $item, $v)];
            }
            return new DXNMap($pairs);
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw InvalidData::expected('a map', $value);
        }
        $out = [];
        foreach ($value as $k => $v) {
            $dxnKey = MapBuilder::toDxnMapKey($k);
            $shown = self::show($dxnKey);
            self::checked($shown, $key, $dxnKey);
            $out[$k] = self::checked($shown, $item, $v);
        }
        return $out;
    }

    /**
     * A map checked as a `%shape`: each declared field matched by name,
     * whatever spelling its key has, and checked, as `$specs` say
     * ({@see self::fields()}); a missing one with a default takes it under
     * a keyword key. With `$closed`, a key no field declares is refused.
     * The value stays the array or {@see DXNMap} it was.
     *
     * @param list<Spec> $specs
     * @return array<int|string, mixed>|DXNMap
     * @throws InvalidData the first field refused, named
     */
    public static function shape(mixed $value, array $specs, bool $closed): array|DXNMap
    {
        if (!is_array($value) && !$value instanceof DXNMap) {
            throw InvalidData::expected('a map', $value);
        }
        $pairs = $value instanceof DXNMap
            ? $value->pairs()
            : array_map(static fn (int|string $k, mixed $v): array => [MapBuilder::toDxnMapKey($k), $v], array_keys($value), array_values($value));
        $at = [];
        foreach ($pairs as $i => [$key]) {
            $name = self::keyName($key);
            if ($name === null) {
                continue;
            }
            if (array_key_exists($name, $at)) {
                throw (new InvalidData('given twice'))->under($name);
            }
            $at[$name] = $i;
        }
        $declared = [];
        foreach ($specs as [$name, $required, $check, $default]) {
            $declared[$name] = true;
            if (array_key_exists($name, $at)) {
                $i = $at[$name];
                $pairs[$i] = [$pairs[$i][0], self::checked($name, $check, $pairs[$i][1])];
            } elseif ($default !== null) {
                $pairs[] = [new DXNKeyword($name), $default()];
            } elseif ($required) {
                throw new InvalidData("missing required field {$name}", [], 'schema/missing-field');
            }
        }
        if ($closed) {
            foreach ($pairs as [$key]) {
                $name = self::keyName($key);
                if ($name === null || !isset($declared[$name])) {
                    throw new InvalidData(self::show($key) . ' is no field, and the shape is closed', [], 'schema/undeclared-field');
                }
            }
        }
        return $value instanceof DXNMap
            ? new DXNMap($pairs)
            : MapBuilder::build($pairs, static fn (string $m): \Throwable => new \LogicException($m));
    }

    /** The name a map key gives a field, or `null`. */
    private static function keyName(mixed $key): ?string
    {
        return match (true) {
            $key instanceof DXNKeyword => $key->name,
            is_string($key) => $key,
            default => null,
        };
    }

    /**
     * The first of `$variants` that takes the value (`{:any-of}`).
     *
     * @param list<\Closure(mixed): mixed> $variants
     * @throws InvalidData when none does
     */
    public static function anyOf(mixed $value, array $variants): mixed
    {
        foreach ($variants as $variant) {
            try {
                return self::attempt($variant, $value);
            } catch (InvalidData) {
            }
        }
        throw InvalidData::expected('one of its variants', $value);
    }

    /**
     * The one of `$variants` that takes the value (`{:one-of}`).
     *
     * @param list<\Closure(mixed): mixed> $variants
     * @throws InvalidData when none does, or more than one
     */
    public static function oneOf(mixed $value, array $variants): mixed
    {
        $taken = [];
        foreach ($variants as $variant) {
            try {
                $taken[] = self::attempt($variant, $value);
            } catch (InvalidData) {
            }
        }
        return match (count($taken)) {
            1 => $taken[0],
            0 => throw InvalidData::expected('one of its variants', $value),
            default => throw new InvalidData('more than one variant takes ' . self::show($value)),
        };
    }

    /**
     * The value through every one of `$variants`, each taking what the one
     * before answered (`{:all-of}`).
     *
     * @param list<\Closure(mixed): mixed> $variants
     * @throws InvalidData the first refusal
     */
    public static function allOf(mixed $value, array $variants): mixed
    {
        foreach ($variants as $variant) {
            $value = $variant($value);
        }
        return $value;
    }

    /**
     * A value `$excluded` does not take (`{:not}`).
     *
     * @param \Closure(mixed): mixed $excluded
     * @throws InvalidData when it takes it
     */
    public static function not(mixed $value, \Closure $excluded): mixed
    {
        try {
            self::attempt($excluded, $value);
        } catch (InvalidData) {
            return $value;
        }
        throw new InvalidData(self::show($value) . ' is what is excluded');
    }

    /**
     * A value `$test` takes checked by `$then`, any other by `$else`
     * (`{:if}`).
     *
     * @param \Closure(mixed): mixed $test
     * @param \Closure(mixed): mixed $then
     * @param \Closure(mixed): mixed $else
     * @throws InvalidData the branch's refusal
     */
    public static function if(mixed $value, \Closure $test, \Closure $then, \Closure $else): mixed
    {
        try {
            self::attempt($test, $value);
        } catch (InvalidData) {
            return $else($value);
        }
        return $then($value);
    }

    // ---- values ----------------------------------------------------------------------

    /**
     * A DXN value written as text, decoded: a constant of the generated
     * code (a default, a literal, a refine's constraints), which caches it.
     *
     * @param string $text the value as DXN text
     * @return mixed the value
     */
    public static function value(string $text): mixed
    {
        return Dextrin::decode($text);
    }

    /**
     * What a value is, for an error: its DXN text, or its type.
     *
     * @param mixed $value the value
     * @return string it, in words
     */
    public static function show(mixed $value): string
    {
        try {
            return Dextrin::encode(self::toDxn($value));
        } catch (\Throwable) {
            return get_debug_type($value);
        }
    }

    /**
     * `$value` written back as DXN: a generated object as its own `toDxn()`
     * writes it, inside lists and maps too.
     *
     * @param mixed $value the value
     * @return mixed it, as DXN values only
     */
    public static function toDxn(mixed $value): mixed
    {
        return match (true) {
            is_object($value) && defined($value::class . '::DXN_SCHEMA') && method_exists($value, 'toDxn') => $value->toDxn(),
            $value instanceof DXNList => new DXNList(array_map(self::toDxn(...), $value->items)),
            $value instanceof DXNMap => new DXNMap(array_map(static fn (array $p): array => [$p[0], self::toDxn($p[1])], $value->pairs())),
            is_array($value) => array_map(self::toDxn(...), $value),
            default => $value,
        };
    }

    /**
     * `$value` with `$read` applied to each value inside a list or map,
     * however deep it goes: what a generated decoder uses to read every
     * struct it knows, wherever it stands.
     *
     * @param \Closure(mixed): mixed $read reads one value, going on with this
     * @return mixed the value, each part read
     */
    public static function walk(mixed $value, \Closure $read): mixed
    {
        return match (true) {
            $value instanceof DXNList => new DXNList(array_map($read, $value->items)),
            $value instanceof DXNMap => new DXNMap(array_map(static fn (array $p): array => [$p[0], $read($p[1])], $value->pairs())),
            is_array($value) => array_map($read, $value),
            default => $value,
        };
    }
}
