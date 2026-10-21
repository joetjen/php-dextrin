<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Value\DXNMap;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Text\Parser;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\Equality;

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
     * already (DXNS.md §8).
     *
     * Every named type in a document sees every other, in any order, and
     * may reach itself through a guard -- a field, or a collection's
     * elements (DXNS.md §5, Jan, 2026-10-20). `$names` maps an entry's
     * key to the name it is registered under -- a tree's full names
     * (DXNS.md §6, {@see Layout}); a bare symbol naming a sibling
     * resolves to it.
     *
     * @param array<string, string> $names
     */
    public static function compile(mixed $schemaDoc, Registry $baseRegistry, array $names = []): Registry
    {
        if (!is_array($schemaDoc)) {
            throw new SchemaCompileError('expected a .dxns document (a map), got ' . self::describe($schemaDoc));
        }
        $outer = self::$names;
        self::$names = $names;
        try {
            return self::compileDocument($schemaDoc, $baseRegistry, $names);
        } finally {
            self::$names = $outer;
        }
    }

    /**
     * The names of the document being compiled (see {@see self::compile()}):
     * set for one compile, which PHP runs one at a time.
     *
     * @var array<string, string>
     */
    private static array $names = [];

    /**
     * @param array<int|string, mixed> $schemaDoc
     * @param array<string, string> $names
     */
    private static function compileDocument(array $schemaDoc, Registry $baseRegistry, array $names): Registry
    {

        /** @var list<array{0: string, 1: mixed}> $schemaEntries */
        $schemaEntries = [];
        /** @var list<array{0: string, 1: mixed}> $aliasEntries */
        $aliasEntries = [];
        foreach ($schemaDoc as $key => $value) {
            $name = $names[(string) $key] ?? (string) $key;
            $bare = self::undoc($value);
            if ($bare instanceof DXNStruct && $bare->name === 'schema') {
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

    /**
     * Every named type in a document sees every other (design/
     * shapes-and-case.md): each is compiled with its siblings standing as
     * `named`, and those are put in place once all are read -- so the
     * order of a map's entries, which DXN does not give, never matters.
     *
     * @param list<array{0: string, 1: mixed}> $aliasEntries
     */
    private static function compileAliases(array $aliasEntries, Registry $registry): Registry
    {
        $scope = $registry->typeAliases();
        foreach ($aliasEntries as [$name]) {
            $scope[$name] = TypeExpr::named($name);
        }
        $compiled = [];
        foreach ($aliasEntries as [$name, $rawValue]) {
            try {
                $compiled[$name] = self::compileNamed($name, $rawValue, $scope);
            } catch (SchemaCompileError $err) {
                throw new SchemaCompileError("named type \"{$name}\": {$err->getMessage()}");
            }
        }
        self::guarded($compiled);
        $known = array_merge($registry->typeAliases(), $compiled);
        $result = $registry;
        foreach ($aliasEntries as [$name]) {
            try {
                $own = $compiled[$name];
                $typeExpr = $own->form === 'generic'
                    ? $own->with(['base' => self::resolved($own->base ?? TypeExpr::any(), $known, [$name])])
                    : self::resolved($own, $known, [$name]);
            } catch (SchemaCompileError $err) {
                throw new SchemaCompileError("named type \"{$name}\": {$err->getMessage()}");
            }
            $result = $result->putTypeAlias($name, $typeExpr);
        }
        return $result;
    }

    /**
     * A named type's definition: a generic's parameters and body, a shape
     * carrying its own name, or a type.
     *
     * @param array<string, TypeExpr> $scope
     */
    private static function compileNamed(string $name, mixed $raw, array $scope): TypeExpr
    {
        // A named type's annotations wrap it whole: a generic keeps them on
        // its body, a shape takes the name as it would bare.
        if (self::isForm($raw, 'doc') && $raw instanceof DXNTuple && count($raw->items) === 3) {
            $compiled = self::compileNamed($name, $raw->items[1], $scope);
            if ($compiled->form === 'generic') {
                $body = $compiled->base ?? TypeExpr::any();
                return TypeExpr::generic($compiled->params ?? [], TypeExpr::doc($body, self::annotations($raw->items[2], $body)));
            }
            return TypeExpr::doc($compiled, self::annotations($raw->items[2], $compiled));
        }
        if ($raw instanceof DXNTuple && ($raw->items[0] ?? null) instanceof DXNKeyword && $raw->items[0]->name === 'generic') {
            $params = $raw->items[1] ?? null;
            $list = $params instanceof DXNList ? $params->items : (is_array($params) ? $params : null);
            if (count($raw->items) !== 3 || $list === null || $list === []) {
                throw new SchemaCompileError("a generic's parameters are symbols: {:generic [t ...] body}");
            }
            $names = [];
            foreach ($list as $p) {
                if (!$p instanceof DXNSymbol) {
                    throw new SchemaCompileError("a generic's parameters are symbols: {:generic [t ...] body}");
                }
                $names[] = $p->name;
            }
            if (count(array_unique($names)) !== count($names)) {
                throw new SchemaCompileError('a generic names a parameter twice');
            }
            $body = $raw->items[2];
            if ($body instanceof DXNStruct && $body->name === 'schema') {
                throw new SchemaCompileError(
                    'a struct schema cannot be generic: a struct goes on the wire under its one name; '
                    . 'make the generic a %shape, or give each use a schema of its own'
                );
            }
            foreach ($names as $n) {
                $scope[$n] = TypeExpr::param($n);
            }
            return TypeExpr::generic($names, self::compileNamed($name, $body, $scope));
        }
        $type = self::compileTypeExpr($raw, $scope);
        return $type->form === 'shape' && $type->name === null ? $type->with(['name' => $name]) : $type;
    }

    /**
     * `$type` with every `named` replaced by that named type; a reference
     * back to a type being resolved closes a cycle, which {@see
     * self::guarded()} has passed, and stays a reference by name.
     *
     * @param array<string, TypeExpr> $known
     * @param list<string> $path
     */
    private static function resolved(TypeExpr $type, array $known, array $path): TypeExpr
    {
        $one = static fn (?TypeExpr $t): ?TypeExpr => $t === null ? null : self::resolved($t, $known, $path);
        $many = static fn (?array $ts): ?array => $ts === null ? null : array_map(static fn (TypeExpr $t): TypeExpr => self::resolved($t, $known, $path), $ts);
        switch ($type->form) {
            case 'named':
                $name = $type->name ?? '';
                if (in_array($name, $path, true)) {
                    return $type;
                }
                $target = $known[$name];
                if ($target->form === 'generic') {
                    throw new SchemaCompileError(self::takesArguments($name, $target));
                }
                return self::resolved($target, $known, [$name, ...$path]);
            case 'apply':
                self::applies($type->name ?? '', count($type->elems ?? []), $known);
                return $type->with(['elems' => $many($type->elems)]);
            case 'generic':
                throw new SchemaCompileError("a generic is a named type's whole definition, {:generic [t ...] body}");
            case 'shape':
                return $type->with(['fields' => array_map(
                    static fn (Field $f): Field => new Field($f->name, $f->required, self::resolved($f->type, $known, $path), $f->default, $f->description),
                    $type->fields ?? [],
                )]);
            case 'case':
                return $type->with([
                    'branches' => array_map(static fn (array $b): array => [$b[0], self::resolved($b[1], $known, $path)], $type->branches ?? []),
                    'otherwise' => $one($type->otherwise),
                ]);
            case 'refine':
                return $type->with([
                    'base' => $one($type->base),
                    'constraints' => TypeExpr::withTypes($type->constraints ?? [], static fn (TypeExpr $t): TypeExpr => self::resolved($t, $known, $path)),
                ]);
            case 'list-items':
                return $type->with(['particles' => array_map(
                    static fn (Particle $p): Particle => $p->map(static fn (TypeExpr $t): TypeExpr => self::resolved($t, $known, $path)),
                    $type->particles ?? [],
                )]);
            case 'tuple-of':
                return $type->with(['elems' => $many($type->elems)]);
            case 'if':
                return $type->with(['base' => $one($type->base), 'elem' => $one($type->elem), 'otherwise' => $one($type->otherwise)]);
            case 'one-of':
            case 'any-of':
            case 'all-of':
                return $type->with(['variants' => $many($type->variants)]);
            case 'list-of':
            case 'set-of':
            case 'array-of':
            case 'sorted-set-of':
            case 'tuple-of-all':
            case 'not':
            case 'nilable':
            case 'tagged':
            case 'doc':
                return $type->with(['elem' => $one($type->elem)]);
            case 'map-of':
            case 'ordered-map-of':
                return $type->with(['key' => $one($type->key), 'value' => $one($type->value)]);
            default:
                return $type;
        }
    }

    /**
     * Every cycle among `$compiled`'s named types passes a guard: a field
     * of a shape, or a collection's elements, so that each step of a
     * check reads a smaller part of the value. A cycle with none --
     * `A: {:nilable A}` -- describes no value and would never finish.
     *
     * @param array<string, TypeExpr> $compiled
     */
    private static function guarded(array $compiled): void
    {
        $edges = array_map(static fn (TypeExpr $t): array => self::unguardedNames($t), $compiled);
        $starts = array_keys($edges);
        sort($starts);
        foreach ($starts as $start) {
            $cycle = self::unguardedCycle((string) $start, $edges, []);
            if ($cycle !== null) {
                throw new SchemaCompileError(
                    "named type \"{$cycle[0]}\": reaches itself with no field or collection between ("
                    . implode(' -> ', $cycle) . '); it describes no value'
                );
            }
        }
    }

    /**
     * @param array<string, list<string>> $edges
     * @param list<string> $path
     * @return list<string>|null
     */
    private static function unguardedCycle(string $name, array $edges, array $path): ?array
    {
        if ($path !== [] && $name === $path[0]) {
            return [...$path, $name];
        }
        if (in_array($name, $path, true)) {
            return null;
        }
        foreach ($edges[$name] ?? [] as $next) {
            $found = self::unguardedCycle($next, $edges, [...$path, $name]);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * The named types `$type` refers to without a guard on the way.
     *
     * @return list<string>
     */
    private static function unguardedNames(TypeExpr $type): array
    {
        $all = static fn (array $ts): array => array_merge([], ...array_map(static fn (TypeExpr $t): array => self::unguardedNames($t), $ts));
        // Everything not named here is a guard, or a leaf: a field, a
        // collection's elements (list-items and tuple-of-all among them),
        // a tagged's value, and a refine's `contains`, each of which reads
        // a smaller part. A doc and an if check the same value.
        return match ($type->form) {
            'named' => [$type->name ?? ''],
            'apply' => [$type->name ?? '', ...$all($type->elems ?? [])],
            'generic' => self::unguardedNames($type->base ?? TypeExpr::any()),
            'one-of', 'any-of', 'all-of' => $all($type->variants ?? []),
            'not', 'nilable', 'doc' => self::unguardedNames($type->elem ?? TypeExpr::any()),
            'if' => $all([$type->base ?? TypeExpr::any(), $type->elem ?? TypeExpr::any(), $type->otherwise ?? TypeExpr::any()]),
            'refine' => self::unguardedNames($type->base ?? TypeExpr::any()),
            'case' => $all([...($type->otherwise ? [$type->otherwise] : []), ...array_map(static fn (array $b): TypeExpr => $b[1], $type->branches ?? [])]),
            default => [],
        };
    }

    /**
     * Whether `$name` is a generic taking `$count` arguments.
     *
     * @param array<string, TypeExpr> $known
     */
    private static function applies(string $name, int $count, array $known): void
    {
        $target = $known[$name] ?? null;
        if ($target !== null && $target->form === 'named') {
            return;
        }
        if ($target === null || $target->form !== 'generic') {
            throw new SchemaCompileError("{:of {$name} ...} names no generic");
        }
        $want = count($target->params ?? []);
        if ($want !== $count) {
            throw new SchemaCompileError("{$name} takes {$want} type argument(s), {:of {$name} ...} gives {$count}");
        }
    }

    private static function takesArguments(string $name, TypeExpr $generic): string
    {
        return "{$name} is generic: write {:of {$name} " . implode(' ', $generic->params ?? []) . '}, with a type for each';
    }

    /**
     * The name a written symbol stands for: itself where it is in scope
     * already (a generic's parameter, a name known so), otherwise the full
     * name this document's names give it.
     *
     * @param array<string, TypeExpr> $aliases
     */
    private static function fullName(string $written, array $aliases): string
    {
        return isset($aliases[$written]) ? $written : (self::$names[$written] ?? $written);
    }

    /**
     * Refuses a schema written in Dextrin 0.1's syntax -- `fields:
     * @ordered %{...}`, `closed: true`, `forbidden: [...]` -- saying so,
     * and what a schema is written as now (DXNS.md §4), rather than that
     * a field has no known type. Only those shapes: now `fields`, `closed`
     * and `forbidden` are ordinary field names, whose values are type
     * expressions. The format stays 1.0 until 1.0.0 (Jan, 2026-10-20), so
     * the old syntax is named by the release that had it.
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
                    "this is Dextrin 0.1's schema syntax (fields:, closed:, forbidden:); a schema lists its fields directly, "
                    . '%schema(N){field: type, ...}, and refuses undeclared fields with strict: true (DXNS.md §4)'
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
    private static function compileEntry(mixed $entry, array $aliases): Compiled
    {
        // A schema's annotations, `{:doc %schema{...} %{...}}`, kept on its
        // Compiled.
        if ($entry instanceof DXNTuple && self::isForm($entry, 'doc') && count($entry->items) === 3) {
            return self::compileEntry($entry->items[1], $aliases)->withAnnotations(self::annotations($entry->items[2], null));
        }
        if (!$entry instanceof DXNStruct || !$entry->isKeyed()) {
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
        self::casesNameFields($entry->pairs(), $fields);
        return new Compiled(fields: $fields, version: $entry->version ?? 1);
    }

    /**
     * Every `{:case field ...}` written in `$pairs`' field types names one
     * of `$fields` -- told from the source, so a named type the case
     * reaches through is not looked into.
     *
     * @param iterable<array{0: string, 1: mixed}> $pairs
     * @param list<Field> $fields
     */
    private static function casesNameFields(iterable $pairs, array $fields): void
    {
        $names = array_map(static fn (Field $f): string => $f->name, $fields);
        foreach ($pairs as [, $value]) {
            foreach (self::caseFields($value) as $field) {
                if (!in_array($field, $names, true)) {
                    throw new SchemaCompileError("{:case {$field} ...} names no field of its schema or shape");
                }
            }
        }
    }

    /** @return list<string> */
    private static function caseFields(mixed $value): array
    {
        if ($value instanceof DXNTuple) {
            $head = $value->items[0] ?? null;
            $second = $value->items[1] ?? null;
            if ($head instanceof DXNKeyword && $head->name === 'case' && $second instanceof DXNSymbol) {
                return [$second->name, ...array_merge([], ...array_map(self::caseFields(...), array_slice($value->items, 2)))];
            }
            return array_merge([], ...array_map(self::caseFields(...), $value->items));
        }
        if ($value instanceof DXNStruct && $value->name === 'shape') {
            return [];
        }
        if ($value instanceof DXNStruct && $value->name === 'field' && $value->isKeyed()) {
            return self::caseFields(self::getField($value->pairs(), 'type'));
        }
        if (is_array($value)) {
            return array_merge([], ...array_map(self::caseFields(...), array_values($value)));
        }
        return [];
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
            if ($value->name === 'never') {
                return TypeExpr::never();
            }
            if (TypeExpr::isPrimitiveName($value->name)) {
                return TypeExpr::primitive($value->name);
            }
            // Sugar (DXNS.md §1, Jan, 2026-10-20): protobuf's and MongoDB's
            // sized numbers as their ranges, `:float32` as single
            // precision, `:number` as every kind of number.
            if (array_key_exists($value->name, self::SIZED)) {
                [$least, $most] = self::SIZED[$value->name];
                return TypeExpr::refine(TypeExpr::primitive('integer'), [
                    'min' => $least,
                    'max' => is_string($most) ? new DXNInteger($most) : $most,
                ]);
            }
            if ($value->name === 'float32') {
                return TypeExpr::refine(TypeExpr::primitive('float'), ['float32' => true]);
            }
            if ($value->name === 'number') {
                return TypeExpr::anyOf(array_map(TypeExpr::primitive(...), ['integer', 'float', 'decimal', 'rational']));
            }
            // DXN 0.2's timestamp type, now a datetime in UTC (DXN.md
            // §4.2, Jan, 2026-10-20): a schema naming it keeps its meaning.
            if ($value->name === 'timestamp') {
                return TypeExpr::refine(TypeExpr::primitive('datetime'), ['offsets' => ['Z']]);
            }
            throw new SchemaCompileError('unrecognized type expression: ' . self::inspect($value));
        }

        if ($value instanceof DXNSymbol) {
            $name = self::fullName($value->name, $aliases);
            $known = $aliases[$name] ?? null;
            if ($known !== null && $known->form === 'generic') {
                throw new SchemaCompileError(self::takesArguments($name, $known));
            }
            return $known ?? TypeExpr::reference($name);
        }

        // `%shape{field: type, ...}` (design/shapes-and-case.md): a plain
        // map's fields, as a schema's are written; no version, never
        // positional.
        if ($value instanceof DXNStruct && $value->name === 'shape') {
            if (!$value->isKeyed()) {
                throw new SchemaCompileError('a shape lists its fields by name: %shape{field: type, ...}');
            }
            if ($value->version !== null) {
                throw new SchemaCompileError('a shape has no version: %shape{field: type, ...}');
            }
            $fields = [];
            $seen = [];
            foreach ($value->pairs() as [$key, $v]) {
                try {
                    $field = self::compileField($key, $v, $aliases);
                } catch (SchemaCompileError $err) {
                    throw new SchemaCompileError("field \"{$key}\": {$err->getMessage()}");
                }
                if (isset($seen[$field->name])) {
                    throw new SchemaCompileError("field \"{$field->name}\" is named twice");
                }
                $seen[$field->name] = true;
                $fields[] = $field;
            }
            self::casesNameFields($value->pairs(), $fields);
            return TypeExpr::shape(null, $fields);
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
            if ($tag === 'array-of' && count($rest) === 1) {
                return TypeExpr::arrayOf(self::compileTypeExpr($rest[0], $aliases));
            }
            if ($tag === 'sorted-set-of' && count($rest) === 1) {
                return TypeExpr::sortedSetOf(self::compileTypeExpr($rest[0], $aliases));
            }
            if ($tag === 'ordered-map-of' && count($rest) === 2) {
                return TypeExpr::orderedMapOf(self::compileTypeExpr($rest[0], $aliases), self::compileTypeExpr($rest[1], $aliases));
            }
            if ($tag === 'enum') {
                return TypeExpr::enum($rest);
            }
            if ($tag === 'any-of') {
                return TypeExpr::anyOf(array_map(
                    static fn (mixed $v): TypeExpr => self::compileTypeExpr($v, $aliases),
                    $rest,
                ));
            }
            if ($tag === 'not' && count($rest) === 1) {
                return TypeExpr::not(self::compileTypeExpr($rest[0], $aliases));
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
            // `{:case field %{value => type, ..., :else => type}}`: the type
            // the sibling `field`'s value chooses.
            if ($tag === 'case') {
                if (count($rest) !== 2 || !$rest[0] instanceof DXNSymbol || !(is_array($rest[1]) || $rest[1] instanceof DXNMap)) {
                    throw new SchemaCompileError('a case is {:case field %{value => type, ..., :else => type}}');
                }
                $pairs = $rest[1] instanceof DXNMap
                    ? $rest[1]->pairs()
                    : array_map(static fn (int|string $k, mixed $v): array => [MapBuilder::toDxnMapKey($k), $v], array_keys($rest[1]), array_values($rest[1]));
                $otherwise = null;
                $branches = [];
                foreach ($pairs as [$k, $v]) {
                    if ($k instanceof DXNKeyword && $k->name === 'else') {
                        $otherwise = self::compileTypeExpr($v, $aliases);
                    } else {
                        $branches[] = [$k, self::compileTypeExpr($v, $aliases)];
                    }
                }
                return TypeExpr::case($rest[0]->name, $branches, $otherwise);
            }
            // `{:of Name type ...}`: one use of the generic `Name`.
            if ($tag === 'of') {
                if (!(($rest[0] ?? null) instanceof DXNSymbol) || count($rest) < 2) {
                    throw new SchemaCompileError('a use of a generic is {:of Name type ...}');
                }
                $name = self::fullName($rest[0]->name, $aliases);
                self::applies($name, count($rest) - 1, $aliases);
                return TypeExpr::apply($name, array_map(
                    static fn (mixed $v): TypeExpr => self::compileTypeExpr($v, $aliases),
                    array_slice($rest, 1),
                ));
            }
            if ($tag === 'generic') {
                throw new SchemaCompileError("a generic is a named type's whole definition, {:generic [t ...] body}");
            }
            if ($tag === 'refine' && count($rest) === 2 && (is_array($rest[1]) || $rest[1] instanceof DXNMap)) {
                $base = self::compileTypeExpr($rest[0], $aliases);
                return TypeExpr::refine($base, self::refineConstraints($rest[1], $aliases));
            }
            // `{:list-items p ...}`: a list's items in order, each `p` a
            // type for one item or a `{:repeat ...}` (DXNS.md §1a).
            if ($tag === 'list-items') {
                return TypeExpr::listItems(self::particles($rest, $aliases));
            }
            if ($tag === 'tuple-of-all' && count($rest) === 1) {
                return TypeExpr::tupleOfAll(self::compileTypeExpr($rest[0], $aliases));
            }
            if ($tag === 'repeat' || $tag === 'group') {
                throw new SchemaCompileError("a {$tag} stands only among {:list-items ...}'s items");
            }
            // `{:tagged name type}`: a custom tag of that name, its value a
            // type (DXNS.md §1).
            if ($tag === 'tagged') {
                $name = count($rest) === 2 ? self::literalName($rest[0]) : null;
                if (count($rest) !== 2) {
                    throw new SchemaCompileError('a tagged is {:tagged name type}');
                }
                if ($name === null) {
                    throw new SchemaCompileError('a tagged is {:tagged name type}, the name a symbol, keyword or string');
                }
                if (in_array($name, Parser::builtInTags(), true)) {
                    throw new SchemaCompileError("{$name} is a built-in tag (DXN.md §1.3), no custom one: its type is :{$name}");
                }
                return TypeExpr::tagged($name, self::compileTypeExpr($rest[1], $aliases));
            }
            // `{:const v}`: the one value `v`, `{:enum v}`.
            if ($tag === 'const') {
                if (count($rest) !== 1) {
                    throw new SchemaCompileError('a const is {:const value}');
                }
                return TypeExpr::enum([$rest[0]]);
            }
            // `{:if I T E}`: JSON Schema's if, then and else; `E` is `:any`
            // when not given.
            if ($tag === 'if') {
                if (count($rest) !== 2 && count($rest) !== 3) {
                    throw new SchemaCompileError('an if is {:if test then else}, else :any when left out');
                }
                return TypeExpr::if(
                    self::compileTypeExpr($rest[0], $aliases),
                    self::compileTypeExpr($rest[1], $aliases),
                    self::compileTypeExpr($rest[2] ?? new DXNKeyword('any'), $aliases),
                );
            }
            // `{:doc type %{...}}`: the type, annotated (DXNS.md §1b).
            if ($tag === 'doc') {
                if (count($rest) !== 2) {
                    throw new SchemaCompileError('a doc is {:doc type %{description: ..., ...}}');
                }
                $type = self::compileTypeExpr($rest[0], $aliases);
                return TypeExpr::doc($type, self::annotations($rest[1], $type));
            }
        }

        throw new SchemaCompileError('unrecognized type expression: ' . self::inspect($value));
    }

    /**
     * A `{:list-items}`'s or a group's items: {@see Particle::one()} for a
     * type, {@see Particle::repeat()} for a repeat, its max `null` when
     * there is no upper bound.
     *
     * @param list<mixed> $values
     * @param array<string, TypeExpr> $aliases
     * @return list<Particle>
     */
    private static function particles(array $values, array $aliases): array
    {
        return array_map(static fn (mixed $v): Particle => self::particle($v, $aliases), $values);
    }

    /** @param array<string, TypeExpr> $aliases */
    private static function particle(mixed $value, array $aliases): Particle
    {
        $items = $value instanceof DXNTuple ? $value->items : [];
        $tag = ($items[0] ?? null) instanceof DXNKeyword ? $items[0]->name : null;
        if ($tag === 'repeat') {
            [$inner, $least, $most] = [$items[1] ?? null, $items[2] ?? null, $items[3] ?? null];
            if (count($items) !== 4 || !is_int($least) || $least < 0 || !($most === null || (is_int($most) && $most >= $least))) {
                throw new SchemaCompileError('a repeat is {:repeat type min max}: 0 <= min <= max, max nil for no bound');
            }
            $group = $inner instanceof DXNTuple && ($inner->items[0] ?? null) instanceof DXNKeyword && $inner->items[0]->name === 'group'
                ? array_slice($inner->items, 1)
                : [$inner];
            return Particle::repeat(self::particles($group, $aliases), $least, $most);
        }
        if ($tag === 'group') {
            throw new SchemaCompileError('a group stands only in a repeat, {:repeat {:group a b ...} min max}');
        }
        return Particle::one(self::compileTypeExpr($value, $aliases));
    }

    /** The sized integers' ranges; a bound past PHP's int is its digits. */
    private const SIZED = [
        'int32' => [-2_147_483_648, 2_147_483_647],
        'int64' => [PHP_INT_MIN, PHP_INT_MAX],
        'uint32' => [0, 4_294_967_295],
        'uint64' => [0, '18446744073709551615'],
    ];

    private const REFINE_KEYS = [
        'min', 'max', 'exclusive-min', 'exclusive-max', 'multiple-of', 'min-length', 'max-length',
        'min-bytes', 'max-bytes', 'pattern', 'min-count', 'max-count', 'finite', 'float32', 'offsets',
        'version', 'prefix', 'contains', 'min-contains', 'max-contains', 'unique', 'unique-by',
        'min-precision', 'max-precision', 'min-exponent', 'max-exponent', 'bits', 'before-now',
        'after-now', 'within', 'resolution', 'format', 'encoding', 'media-type', 'content', 'encodes',
        'encodes-seq',
    ];

    private const COUNTS = [
        'min-length', 'max-length', 'min-bytes', 'max-bytes', 'min-count', 'max-count', 'min-contains',
        'max-contains', 'min-precision', 'max-precision',
    ];
    private const FLAGS = ['finite', 'float32', 'unique', 'before-now', 'after-now', 'prefix'];
    private const BOUNDS = ['min', 'max', 'exclusive-min', 'exclusive-max'];
    private const ENCODINGS = ['base64', 'base64url', 'base32', 'base16'];

    /** What `content` reads a string as, by its media type: none, or one of these. */
    private const CONTENT_READERS = ['application/dxn', 'application/dxnb'];

    /**
     * A refine's constraints by name, every key one DXNS.md §2 knows and
     * every argument one it takes: a misspelt key would otherwise refuse
     * every value, silently, and a bad argument fail at check time. The
     * types ({@see TypeExpr::TYPE_KEYS}) are compiled here; `unique-by`,
     * `format`, `resolution` and `encoding` become names.
     *
     * @param array<int|string, mixed>|DXNMap $constraints
     * @param array<string, TypeExpr> $aliases
     * @return array<string, mixed>
     */
    private static function refineConstraints(array|DXNMap $constraints, array $aliases): array
    {
        $named = self::constraintNames($constraints);
        self::knownKeys($named);
        if (!array_key_exists('contains', $named) && (array_key_exists('min-contains', $named) || array_key_exists('max-contains', $named))) {
            throw new SchemaCompileError('min-contains and max-contains count the items a contains matches');
        }
        $named = self::namedArg($named, 'unique-by', null);
        $named = self::namedArg($named, 'format', Format::names());
        $named = self::namedArg($named, 'resolution', TypeExpr::resolutions());
        $named = self::namedArg($named, 'encoding', self::ENCODINGS);
        self::arguments($named);
        self::contentReadable($named);
        foreach (TypeExpr::TYPE_KEYS as $key) {
            if (array_key_exists($key, $named)) {
                $named[$key] = self::compileTypeExpr($named[$key], $aliases);
            }
        }
        return $named;
    }

    /** @param array<string, mixed> $named */
    private static function knownKeys(array $named): void
    {
        $keys = array_keys($named);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            if (!in_array($key, self::REFINE_KEYS, true)) {
                throw new SchemaCompileError("{$key} is no refine constraint (DXNS.md §2)");
            }
        }
    }

    /**
     * Every argument the kind its key takes, in key order.
     *
     * @param array<string, mixed> $named
     */
    private static function arguments(array $named): void
    {
        ksort($named, SORT_STRING);
        foreach ($named as $key => $arg) {
            $what = self::argument($key, $arg);
            if ($what !== null) {
                throw new SchemaCompileError("{$key} takes {$what}, not " . self::inspect($arg));
            }
        }
    }

    /** What `$key` takes when `$arg` is not one, `null` when it is. */
    private static function argument(string $key, mixed $arg): ?string
    {
        return match (true) {
            in_array($key, self::COUNTS, true) => self::isInteger($arg, true) ? null : 'a non-negative integer',
            $key === 'min-exponent', $key === 'max-exponent' => self::isInteger($arg, false) ? null : 'an integer',
            in_array($key, self::FLAGS, true) => is_bool($arg) ? null : 'true or false',
            in_array($key, self::BOUNDS, true) => TypeExpr::comparable($arg)
                ? null
                : 'a number, date, time, datetime, local date-time or a duration without years or months',
            $key === 'multiple-of' => TypeExpr::nonzeroNumber($arg) ? null : 'a number other than 0',
            $key === 'pattern' => $arg instanceof DXNRegex ? null : 'a regex, @regex "..."',
            $key === 'version' => in_array($arg, [4, 6], true) ? null : '4 or 6',
            $key === 'offsets' => self::allOf($arg, self::isOffset(...)) ? null : 'a list of offsets, "Z" or "±HH:MM"',
            $key === 'bits' => self::allOf($arg, static fn (mixed $n): bool => self::isInteger($n, true))
                ? null
                : 'a list of bit numbers, non-negative integers',
            $key === 'within' => TypeExpr::span($arg) ? null : 'a duration without years or months',
            // RFC 6838's type/subtype, without parameters.
            $key === 'media-type' => is_string($arg) && preg_match('/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]{0,126}\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]{0,126}$/D', $arg) === 1
                ? null
                : 'a media type, "type/subtype"',
            default => null,
        };
    }

    /** An integer, a big one too, and not below zero when `$nonNegative`. */
    private static function isInteger(mixed $arg, bool $nonNegative): bool
    {
        return match (true) {
            is_int($arg) => !$nonNegative || $arg >= 0,
            $arg instanceof DXNInteger => !$nonNegative || !str_starts_with($arg->toString(), '-'),
            default => false,
        };
    }

    /**
     * Whether `$arg` is a list every item of which `$test` takes. A list,
     * `[...]`, as the reference asks: a native PHP list is DXN's
     * `@array[...]` here, which it refuses.
     *
     * @param \Closure(mixed): bool $test
     */
    private static function allOf(mixed $arg, \Closure $test): bool
    {
        if (!$arg instanceof DXNList) {
            return false;
        }
        foreach ($arg->items as $item) {
            if (!$test($item)) {
                return false;
            }
        }
        return true;
    }

    /** `Z`, or `±HH:MM`. */
    private static function isOffset(mixed $text): bool
    {
        return $text === 'Z' || (is_string($text) && preg_match('/^[+-](?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $text) === 1);
    }

    /**
     * `content` reads a DXN document: text, or `.dxnb` under
     * application/dxnb, which a string holds only encoded.
     *
     * @param array<string, mixed> $named
     */
    private static function contentReadable(array $named): void
    {
        if (!array_key_exists('content', $named)) {
            return;
        }
        $media = $named['media-type'] ?? null;
        $media = is_string($media) ? strtolower($media) : $media;
        if ($media !== null && !in_array($media, self::CONTENT_READERS, true)) {
            throw new SchemaCompileError('content reads application/dxn or application/dxnb, not ' . self::inspect($media));
        }
        if ($media === 'application/dxnb' && !array_key_exists('encoding', $named)) {
            throw new SchemaCompileError('content under application/dxnb needs an encoding: a string holds no raw bytes');
        }
    }

    /**
     * The constraints keyed by their names; a key that is no name is an
     * error, not a crash.
     *
     * @param array<int|string, mixed>|DXNMap $constraints
     * @return array<string, mixed>
     */
    private static function constraintNames(array|DXNMap $constraints): array
    {
        $pairs = $constraints instanceof DXNMap
            ? $constraints->pairs()
            : array_map(static fn (int|string $k, mixed $v): array => [MapBuilder::toDxnMapKey($k), $v], array_keys($constraints), array_values($constraints));
        $named = [];
        foreach ($pairs as [$key, $value]) {
            $name = self::literalName($key)
                ?? throw new SchemaCompileError("a refine constraint's key is a name, not " . self::inspect($key));
            $named[$name] = $value;
        }
        return $named;
    }

    /**
     * `$key`'s argument as a name, one of `$allowed` when they are given.
     *
     * @param array<string, mixed> $named
     * @param list<string>|null $allowed
     * @return array<string, mixed>
     */
    private static function namedArg(array $named, string $key, ?array $allowed): array
    {
        if (!array_key_exists($key, $named)) {
            return $named;
        }
        $arg = $named[$key];
        $name = self::literalName($arg);
        if ($name === null || ($allowed !== null && !in_array($name, $allowed, true))) {
            throw new SchemaCompileError("{$key} takes " . self::expected($allowed) . ', not ' . self::inspect($arg));
        }
        $named[$key] = $name;
        return $named;
    }

    /** @param list<string>|null $allowed */
    private static function expected(?array $allowed): string
    {
        if ($allowed === null) {
            return 'a name';
        }
        sort($allowed, SORT_STRING);
        return 'one of ' . implode(' ', array_map(static fn (string $name): string => ":{$name}", $allowed));
    }

    /** `$value` as DXN text, for a message; its PHP type when it has none. */
    private static function inspect(mixed $value): string
    {
        try {
            return Canonical::print($value);
        } catch (\Throwable) {
            return self::describe($value);
        }
    }

    /** The name a keyword, symbol or string gives, or `null`. */
    private static function literalName(mixed $value): ?string
    {
        return match (true) {
            $value instanceof DXNKeyword, $value instanceof DXNSymbol => $value->name,
            is_string($value) => $value,
            default => null,
        };
    }

    // ---- annotations, {:doc T %{...}} (DXNS.md §1b) ---------------------------

    private const ANNOTATION_KEYS = ['title', 'description', 'examples', 'read-only', 'write-only', 'deprecated', 'meta', 'values'];

    /** Whether `$value` is a tuple headed by the keyword `$form`. */
    private static function isForm(mixed $value, string $form): bool
    {
        return $value instanceof DXNTuple && ($value->items[0] ?? null) instanceof DXNKeyword && $value->items[0]->name === $form;
    }

    /** An entry's value without the `{:doc ...}` around it. */
    private static function undoc(mixed $value): mixed
    {
        return $value instanceof DXNTuple && self::isForm($value, 'doc') && count($value->items) === 3 ? $value->items[1] : $value;
    }

    /**
     * Whether `$value` is a map: a {@see DXNMap}, or a native array not
     * shaped like a list (a native list is an `@array`); the empty one is
     * both, and taken.
     *
     * @phpstan-assert-if-true array<int|string, mixed>|DXNMap $value
     */
    private static function isMap(mixed $value): bool
    {
        return $value instanceof DXNMap || (is_array($value) && ($value === [] || !array_is_list($value)));
    }

    /**
     * A map's pairs, each key as DXN has it.
     *
     * @param array<int|string, mixed>|DXNMap $map
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function pairsOf(array|DXNMap $map): array
    {
        return $map instanceof DXNMap
            ? $map->pairs()
            : array_map(static fn (int|string $k, mixed $v): array => [MapBuilder::toDxnMapKey($k), $v], array_keys($map), array_values($map));
    }

    /**
     * A doc's annotations by name, each of its kind (DXNS.md §1b), and
     * each enum value's under `values` keyed by name as well. `$type` is
     * what they annotate -- `null` for a whole schema or one enum value --
     * so `values` can be checked against an enum's literals.
     *
     * @return array<string, mixed>
     */
    private static function annotations(mixed $raw, ?TypeExpr $type): array
    {
        if (!self::isMap($raw)) {
            throw new SchemaCompileError("a doc's annotations are a map, %{description: ...}, not " . self::inspect($raw));
        }
        $named = self::constraintNames($raw);
        $keys = array_keys($named);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $what = self::annotation((string) $key, $named[$key], $type);
            if ($what !== null) {
                throw new SchemaCompileError("a doc's {$key} is {$what}, not " . self::inspect($named[$key]));
            }
        }
        if (array_key_exists('values', $named) && self::isMap($named['values'])) {
            $named['values'] = MapBuilder::build(
                array_map(static fn (array $pair): array => [$pair[0], self::annotations($pair[1], null)], self::pairsOf($named['values'])),
                static fn (string $m): \Throwable => new SchemaCompileError($m),
            );
        }
        return $named;
    }

    /**
     * What the annotation `$key` takes when `$value` is not one, `null`
     * when it is; a key that is none, or an enum value's annotations that
     * are wrong, throw.
     */
    private static function annotation(string $key, mixed $value, ?TypeExpr $type): ?string
    {
        return match ($key) {
            'title', 'description' => is_string($value) ? null : 'a string',
            'examples' => $value instanceof DXNList ? null : 'a list',
            'read-only', 'write-only' => is_bool($value) ? null : 'true or false',
            'deprecated' => is_bool($value) || is_string($value) ? null : 'true, false or a reason',
            'meta' => self::isMap($value) ? null : 'a map',
            'values' => $type !== null && $type->form === 'enum' && self::isMap($value)
                ? self::valueAnnotations($value, $type->literals ?? [])
                : "a map of an enum's values to their annotations, on an enum",
            default => throw new SchemaCompileError("{$key} is no annotation; a doc holds " . implode(', ', self::ANNOTATION_KEYS)),
        };
    }

    /**
     * An enum's `values:`: each key one of its literals, each value a map
     * of that value's own annotations, which hold no `values` of their own.
     * Always `null`; a wrong one throws.
     *
     * @param array<int|string, mixed>|DXNMap $values
     * @param list<mixed> $literals
     */
    private static function valueAnnotations(array|DXNMap $values, array $literals): null
    {
        $pairs = self::pairsOf($values);
        usort($pairs, static fn (array $a, array $b): int => Equality::dxnCompare($a[0], $b[0]));
        foreach ($pairs as [$literal, $notes]) {
            $known = false;
            foreach ($literals as $l) {
                $known = $known || Equality::dxnEquals($l, $literal);
            }
            if (!$known) {
                throw new SchemaCompileError("a doc's values annotate the enum's own values; " . self::inspect($literal) . ' is none of them');
            }
            if (!self::isMap($notes)) {
                throw new SchemaCompileError("a doc's values hold a map of annotations for each value");
            }
            foreach (self::pairsOf($notes) as [$k]) {
                if ($k instanceof DXNKeyword && $k->name === 'values') {
                    throw new SchemaCompileError("a value's annotations hold no values of their own");
                }
            }
            self::annotations($notes, null);
        }
        return null;
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
