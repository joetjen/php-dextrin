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
 * own keys (`fields:`, `closed:`, ...) to strings on the way in (its
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
     * `$baseRegistry` -- a struct schema for each `%schema{}` entry, a
     * named type_expr for every other entry. `$predicates` resolves
     * `refine-fn:` names -- a plain array, not the struct registry
     * itself, since a refine-fn is a validity check, not a decoder.
     *
     * Named types defined in *this* document may reference any type
     * already known to `$baseRegistry` (from an earlier `compile()`
     * call), but not one declared alongside them in the same document
     * -- `.dxns`'s own `map` type has no ordering guarantee to resolve
     * forward references against. A schema's *fields*, compiled
     * afterwards, can freely use any named type from either source.
     *
     * @param array<string, (\Closure(array<string, mixed>): (bool|string))> $predicates
     */
    public static function compile(mixed $schemaDoc, Registry $baseRegistry, array $predicates = []): Registry
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
            $compiled = self::compileEntry($entry, $predicates, $registry->typeAliases())->withName($name);
            $registry = $registry->putStructSchema($name, $compiled);
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
     * @param array<string, (\Closure(array<string, mixed>): (bool|string))> $predicates
     * @param array<string, TypeExpr> $aliases
     */
    private static function compileEntry(DXNStruct $entry, array $predicates, array $aliases): Compiled
    {
        if (!$entry->isKeyed()) {
            throw new SchemaCompileError('expected a %schema{} entry with named fields, got a positional struct');
        }
        $pairs = $entry->pairs();
        $fieldsValue = self::getField($pairs, 'fields');
        $closed = self::getField($pairs, 'closed', false) === true;
        $forbiddenValue = self::getField($pairs, 'forbidden');
        $forbidden = $forbiddenValue instanceof DXNList ? array_map(self::literalName(...), $forbiddenValue->items) : [];
        $refineFnName = self::getField($pairs, 'refine-fn');

        $fields = self::compileFields($fieldsValue, $aliases);
        $refineFn = self::resolveRefineFn($refineFnName, $predicates);
        return new Compiled(fields: $fields, closed: $closed, forbidden: $forbidden, refineFn: $refineFn);
    }

    /**
     * @param array<string, TypeExpr> $aliases
     * @return list<Field>
     */
    private static function compileFields(mixed $value, array $aliases): array
    {
        if (!$value instanceof DXNOrderedMap) {
            throw new SchemaCompileError(
                'expected fields: to be an @ordered %{...} (field order is load-bearing for .dxnb\'s positional '
                . 'encoding), got ' . self::describe($value),
            );
        }
        return array_map(
            static fn (array $pair): Field => self::compileField($pair[0], $pair[1], $aliases),
            $value->pairs,
        );
    }

    /** @param array<string, TypeExpr> $aliases */
    private static function compileField(mixed $key, mixed $value, array $aliases): Field
    {
        if (!$key instanceof DXNKeyword) {
            throw new SchemaCompileError('expected a field name (keyword), got ' . self::describe($key));
        }
        $optional = str_ends_with($key->name, '?');
        $name = $optional ? substr($key->name, 0, -1) : $key->name;

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

    private static function literalName(mixed $value): string
    {
        return match (true) {
            $value instanceof DXNKeyword => $value->name,
            $value instanceof DXNSymbol => $value->name,
            is_string($value) => $value,
            default => throw new SchemaCompileError('expected an identifier or string, got ' . self::describe($value)),
        };
    }

    /**
     * @param array<string, (\Closure(array<string, mixed>): (bool|string))> $predicates
     * @return (\Closure(array<string, mixed>): (bool|string))|null
     */
    private static function resolveRefineFn(mixed $nameValue, array $predicates): ?\Closure
    {
        if ($nameValue === null) {
            return null;
        }
        $name = self::literalName($nameValue);
        if (!isset($predicates[$name])) {
            throw new SchemaCompileError("refine-fn \"{$name}\" not found in the predicates given to compile()");
        }
        return $predicates[$name];
    }

    private static function describe(mixed $value): string
    {
        return get_debug_type($value);
    }
}
