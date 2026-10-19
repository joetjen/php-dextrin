<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTuple;

/**
 * Compiles a parsed `.dxns` document (an ordinary decoded DXN value --
 * a `.dxns` file is valid `.dxn`, no new grammar; what makes it a
 * *schema* document is purely the shape of the value it parses to: a
 * `map` from name to type expression -- a bare PHP array in this port)
 * into {@see Compiled} entries.
 *
 * `%schema{}`/`%field{}` are recognized here directly, as {@see
 * DXNStruct} instances named `"schema"`/`"field"` straight out of
 * `Dextrin::decode()` -- never looked up in a registry, the same way
 * built-in tags are recognized by name in the text/binary codecs
 * rather than registered. This sidesteps an obvious bootstrapping
 * question (doesn't parsing a `.dxns` file need a schema for its own
 * `schema`/`field` structs?) the same way JSON Schema's own vocabulary
 * is fixed tooling knowledge rather than itself validated against a
 * schema.
 *
 * An entry whose value *isn't* a `%schema{}` is a **named type**
 * instead: a reusable name for some combination of the fixed
 * `type_expr` vocabulary, e.g. `PositiveInt: {:refine :integer
 * {min: 1}}` -- purely data, no PHP code, no registry callback, so any
 * conformant reader in any language resolves it the same way.
 *
 * A struct's field names, and a document's own top-level entry names,
 * are always plain PHP strings by the time they reach this class --
 * {@see \JOetjen\Dextrin\Text\Parser} already reduces a `%schema{}`'s
 * own keys -- its field names -- to strings on the way in (its
 * own `structFieldName()`), and the document's top-level `map` keys
 * were already coerced to PHP array keys by `MapBuilder` -- unlike the
 * JS port's own `entryName()`/`normalizeKeywords()` helpers, neither
 * is needed here.
 */
final class Compiler
{
    private const PRIMITIVES_ANY = 'any';

    private function __construct()
    {
    }

    /**
     * Compiles every entry of a decoded `.dxns` document into
     * `$baseRegistry` -- a struct schema for each `%schema(N){}` entry,
     * a named type_expr for every other entry. A refusal is a
     * {@see SchemaCompileError} (category `schema/compile`), or a
     * {@see \JOetjen\Dextrin\DXNError} of category
     * `schema/duplicate-version` for a version the registry has loaded
     * already (DXN.md §4.8).
     *
     * Named types defined in *this* document may reference any type
     * already known to `$baseRegistry` (from an earlier `compile()`
     * call), but not one declared alongside them in the same document
     * -- `.dxns`'s own `map` type has no ordering guarantee to resolve
     * forward references against. A schema's *fields*, compiled
     * afterwards, can freely use any named type from either source.
     */
    public static function compile(mixed $schemaDoc, Registry $baseRegistry): Registry
    {
        if (!is_array($schemaDoc)) {
            throw new SchemaCompileError('expected a .dxns document (a map), got ' . self::describe($schemaDoc));
        }

        /** @var list<array{0: string, 1: DXNStruct}> $schemaEntries */
        $schemaEntries = [];
        /** @var list<array{0: string, 1: mixed}> $aliasEntries */
        $aliasEntries = [];
        foreach ($schemaDoc as $key => $value) {
            $name = (string) $key;
            if ($value instanceof DXNStruct && $value->name === 'schema') {
                $schemaEntries[] = [$name, $value];
            } else {
                $aliasEntries[] = [$name, $value];
            }
        }

        $registry = self::compileAliases($aliasEntries, $baseRegistry);
        foreach ($schemaEntries as [$name, $entry]) {
            try {
                $compiled = self::compileEntry($entry, $registry->typeAliases())->withName($name);
            } catch (SchemaCompileError $err) {
                throw new SchemaCompileError("schema \"{$name}\": {$err->getMessage()}");
            }
            $registry = $registry->addStructSchema($name, $compiled);
        }
        return $registry;
    }

    /** @param list<array{0: string, 1: mixed}> $aliasEntries */
    private static function compileAliases(array $aliasEntries, Registry $registry): Registry
    {
        // `$baseAliases` is a frozen snapshot, not the accumulating
        // registry -- every alias in *this* document resolves
        // references against only what `$registry` already knew
        // coming in, never against a sibling compiled earlier in this
        // same loop (see this class's own doc for why).
        $baseAliases = $registry->typeAliases();
        $result = $registry;
        foreach ($aliasEntries as [$name, $rawValue]) {
            try {
                $typeExpr = self::compileTypeExpr($rawValue, $baseAliases);
            } catch (SchemaCompileError $err) {
                throw new SchemaCompileError("named type \"{$name}\": {$err->getMessage()}");
            }
            $result = $result->putTypeAlias($name, $typeExpr);
        }
        return $result;
    }

    /**
     * Refuses a schema written in DXN 1.0's syntax -- `fields: @ordered
     * %{...}`, `closed: true`, `forbidden: [...]` -- saying so, and what
     * 1.1 writes instead (DXN.md §4.4), rather than that a field has no
     * known type. Only those shapes: in 1.1 `fields`, `closed` and
     * `forbidden` are ordinary field names, whose values are type
     * expressions.
     *
     * @param iterable<array{0: string, 1: mixed}> $pairs
     */
    private static function notFormat10(iterable $pairs): void
    {
        foreach ($pairs as [$key, $value]) {
            if (($key === 'fields' && $value instanceof DXNOrderedMap)
                || ($key === 'closed' && is_bool($value))
                || ($key === 'forbidden' && $value instanceof DXNList)) {
                throw new SchemaCompileError(
                    "this is DXN 1.0's schema syntax (fields:, closed:, forbidden:); DXN 1.1 lists the fields directly, "
                    . '%schema(N){field: type, ...}, and refuses undeclared fields with strict: true (DXN.md §4.4)'
                );
            }
        }
    }

    /**
     * One `%schema(N){field: type, ...}` entry: the body is the fields,
     * in the order they are written; the header is the version, 1 when
     * there is none.
     *
     * @param array<string, TypeExpr> $aliases
     */
    private static function compileEntry(DXNStruct $entry, array $aliases): Compiled
    {
        if (!$entry->isKeyed()) {
            throw new SchemaCompileError('a schema lists its fields by name: %schema(N){field: type, ...}');
        }
        self::notFormat10($entry->pairs());
        $fields = [];
        $seen = [];
        foreach ($entry->pairs() as [$key, $value]) {
            try {
                $field = self::compileField($key, $value, $aliases);
            } catch (SchemaCompileError $err) {
                throw new SchemaCompileError("field \"{$key}\": {$err->getMessage()}");
            }
            if (isset($seen[$field->name])) {
                throw new SchemaCompileError("field \"{$field->name}\" is named twice");
            }
            $seen[$field->name] = true;
            $fields[] = $field;
        }
        return new Compiled(fields: $fields, version: $entry->version ?? 1);
    }

    /**
     * @param string $key a field name as a struct's keyed pairs hold it.
     * @param array<string, TypeExpr> $aliases
     */
    private static function compileField(string $key, mixed $value, array $aliases): Field
    {
        $optional = str_ends_with($key, '?');
        $name = $optional ? substr($key, 0, -1) : $key;

        if ($value instanceof DXNStruct && $value->name === 'field' && $value->isKeyed()) {
            $fieldPairs = $value->pairs();
            $type = self::hasField($fieldPairs, 'type')
                ? self::compileTypeExpr(self::getField($fieldPairs, 'type'), $aliases)
                : TypeExpr::any();
            $default = self::hasField($fieldPairs, 'default') ? self::getField($fieldPairs, 'default') : Field::NO_DEFAULT;
            $descriptionRaw = self::getField($fieldPairs, 'description');
            return new Field(
                name: $name,
                required: !$optional,
                type: $type,
                default: $default,
                description: is_string($descriptionRaw) ? $descriptionRaw : null,
            );
        }

        return new Field(name: $name, required: !$optional, type: self::compileTypeExpr($value, $aliases));
    }

    // ---- type expressions -----------------------------------------------------

    /** @param array<string, TypeExpr> $aliases */
    private static function compileTypeExpr(mixed $value, array $aliases): TypeExpr
    {
        if ($value === null) {
            return TypeExpr::primitive('nil');
        }

        if ($value instanceof DXNKeyword) {
            if ($value->name === self::PRIMITIVES_ANY) {
                return TypeExpr::any();
            }
            if (TypeExpr::isPrimitiveName($value->name)) {
                return TypeExpr::primitive($value->name);
            }
            throw new SchemaCompileError("unrecognized primitive type name \"{$value->name}\"");
        }

        if ($value instanceof DXNSymbol) {
            if (isset($aliases[$value->name])) {
                return $aliases[$value->name];
            }
            return TypeExpr::reference($value->name);
        }

        if ($value instanceof DXNTuple) {
            $items = $value->items;
            $head = $items[0] ?? null;
            $tag = $head instanceof DXNKeyword ? $head->name : null;
            $rest = array_slice($items, 1);

            if ($tag === 'list-of' && count($rest) === 1) {
                return TypeExpr::listOf(self::compileTypeExpr($rest[0], $aliases));
            }
            if ($tag === 'set-of' && count($rest) === 1) {
                return TypeExpr::setOf(self::compileTypeExpr($rest[0], $aliases));
            }
            if ($tag === 'tuple-of') {
                return TypeExpr::tupleOf(array_map(
                    static fn (mixed $v): TypeExpr => self::compileTypeExpr($v, $aliases),
                    $rest,
                ));
            }
            if ($tag === 'map-of' && count($rest) === 2) {
                return TypeExpr::mapOf(self::compileTypeExpr($rest[0], $aliases), self::compileTypeExpr($rest[1], $aliases));
            }
            if ($tag === 'enum') {
                return TypeExpr::enum($rest);
            }
            if ($tag === 'one-of') {
                return TypeExpr::oneOf(array_map(
                    static fn (mixed $v): TypeExpr => self::compileTypeExpr($v, $aliases),
                    $rest,
                ));
            }
            if ($tag === 'all-of') {
                return TypeExpr::allOf(array_map(
                    static fn (mixed $v): TypeExpr => self::compileTypeExpr($v, $aliases),
                    $rest,
                ));
            }
            if ($tag === 'nilable' && count($rest) === 1) {
                return TypeExpr::nilable(self::compileTypeExpr($rest[0], $aliases));
            }
            if ($tag === 'refine' && count($rest) === 2 && is_array($rest[1])) {
                /** @var array<string, mixed> $constraints */
                $constraints = $rest[1];
                return TypeExpr::refine(self::compileTypeExpr($rest[0], $aliases), $constraints);
            }
        }

        throw new SchemaCompileError('unrecognized type expression: ' . self::describe($value));
    }

    // ---- helpers ----------------------------------------------------------------

    /** @param list<array{0: string, 1: mixed}> $pairs */
    private static function getField(array $pairs, string $key, mixed $fallback = null): mixed
    {
        foreach ($pairs as [$k, $v]) {
            if ($k === $key) {
                return $v;
            }
        }
        return $fallback;
    }

    /** @param list<array{0: string, 1: mixed}> $pairs */
    private static function hasField(array $pairs, string $key): bool
    {
        foreach ($pairs as [$k, $unusedValue]) {
            if ($k === $key) {
                return true;
            }
        }
        return false;
    }

    private static function describe(mixed $value): string
    {
        return get_debug_type($value);
    }
}
