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
 * a `.dxns` file is valid `.dxn`, whose one piece of grammar for schemas
 * is what a `%schema` or `%shape` is built from, DXNS.md §4b; what makes
 * it a *schema* document is purely the shape of the value it parses to:
 * a `map` from name to type expression -- a bare PHP array in this port)
 * into {@see Compiled} entries.
 *
 * A schema or shape built from others -- `%schema(2) extends User
 * includes Audit {- nick, role: :string}` -- is flattened here into an
 * ordinary one under its own name ({@see Bases} merges the fields); a
 * compiled schema records its bases with the version each was taken at.
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
 *
 * @phpstan-import-type Extras from TypeExpr
 * @phpstan-type Built array{fields: list<Field>, bases: list<array{0: 'extends'|'includes', 1: string, 2: ?int}>, extras: Extras}
 * @phpstan-type Body array{0: list<Field>, 1: Extras}
 * @phpstan-type Lookup array{document: array<string, mixed>, compiled: array<string, TypeExpr>, scope: array<string, TypeExpr>, registry: Registry}
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
        $outer = [self::$names, self::$document, self::$loaded, self::$versions, self::$pinSource];
        self::$names = $names;
        self::$loaded = $baseRegistry;
        self::$versions = [];
        self::$pinSource = $baseRegistry;
        self::$document = [];
        foreach ($schemaDoc as $key => $value) {
            self::$document[$names[(string) $key] ?? (string) $key] = $value;
        }
        try {
            return self::compileDocument($schemaDoc, $baseRegistry, $names);
        } finally {
            [self::$names, self::$document, self::$loaded, self::$versions, self::$pinSource] = $outer;
        }
    }

    /**
     * The entries of the document being compiled, by full name, and the
     * registry it is compiled into, as it was before: what a generic
     * parameter's bound asks when it asks which schema extends which (G2
     * of dextrin's design/typespec.md). Set for one compile, as
     * {@see self::$names} is.
     *
     * @var array<string, mixed>
     */
    private static array $document = [];

    private static ?Registry $loaded = null;

    /**
     * The version each named type of the document being compiled has
     * (Way B, DXNS.md §5c), so a pin to a sibling (`Color(2)`) is seen;
     * and the registry a pin to a named type finds its versions in -- the
     * one compiled into so far. Set for one compile, as
     * {@see self::$names} is.
     *
     * @var array<string, int>
     */
    private static array $versions = [];

    private static ?Registry $pinSource = null;

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
                try {
                    [$type, self::$versions[$name]] = self::typeVersion($value);
                } catch (SchemaCompileError $err) {
                    throw new SchemaCompileError("named type \"{$name}\": {$err->getMessage()}");
                }
                $aliasEntries[] = [$name, $type];
            }
        }

        [$registry, $built] = self::compileAliases($aliasEntries, $baseRegistry, $schemaEntries);
        self::$pinSource = $registry;
        foreach ($schemaEntries as [$name, $entry]) {
            try {
                $compiled = isset($built[$name])
                    ? self::builtSchema($entry, $built[$name], $registry->typeAliases())->withName($name)
                    : self::compileEntry($entry, $registry->typeAliases())->withName($name);
            } catch (SchemaCompileError $err) {
                throw new SchemaCompileError("schema \"{$name}\": {$err->getMessage()}");
            }
            // A discriminator in a field's type has its variants checked
            // as a named type's are.
            self::discriminators(
                [$name => TypeExpr::shape($name, $compiled->fields, $compiled->extras)],
                ['document' => self::$document, 'compiled' => $registry->typeAliases(), 'scope' => $registry->typeAliases(), 'registry' => $registry],
                $built,
            );
            $registry = $registry->addStructSchema($name, $compiled);
        }
        $entries = [];
        foreach ($schemaDoc as $key => $value) {
            $entries[] = [$names[(string) $key] ?? (string) $key, $value];
        }
        return $registry->withWarnings(Deprecations::compileWarnings($entries, $names, $registry));
    }

    /**
     * `Color: %type(2)[T]` (Way B, DXNS.md §5c): the named type's
     * definition `T`, at version 2; a plain definition is version 1. A
     * `{:doc}` around the `%type` stays around `T`.
     *
     * @return array{0: mixed, 1: int}
     */
    private static function typeVersion(mixed $raw): array
    {
        if ($raw instanceof DXNTuple && self::isForm($raw, 'doc') && count($raw->items) === 3) {
            [$type, $version] = self::typeVersion($raw->items[1]);
            return [new DXNTuple([$raw->items[0], $type, $raw->items[2]]), $version];
        }
        if (!$raw instanceof DXNStruct || $raw->name !== 'type') {
            return [$raw, 1];
        }
        if ($raw->version === null || !$raw->isPositional() || count($raw->values()) !== 1 || $raw->extra !== [] || $raw->bases !== []) {
            throw new SchemaCompileError('a version of a named type is %type(N)[type]: one type, at version N');
        }
        return [$raw->values()[0], $raw->version];
    }

    /**
     * A schema built from others (DXNS.md §4b): the fields and extras
     * `$built` holds for it, each now resolved, at the version its header
     * gives, with the annotations a `{:doc ...}` around it says.
     *
     * @param Built $built
     * @param array<string, TypeExpr> $known
     */
    private static function builtSchema(mixed $entry, array $built, array $known): Compiled
    {
        $raw = self::undoc($entry);
        $annotations = $entry instanceof DXNTuple && self::isForm($entry, 'doc') && count($entry->items) === 3
            ? self::annotations($entry->items[2], null)
            : [];
        $fields = array_map(
            static fn (Field $f): Field => new Field($f->name, $f->required, self::resolved($f->type, $known, []), $f->default, $f->description),
            $built['fields'],
        );
        $extras = $built['extras'];
        return new Compiled(
            fields: $fields,
            version: $raw instanceof DXNStruct ? ($raw->version ?? 1) : 1,
            annotations: $annotations,
            bases: $built['bases'],
            extras: TypeExpr::mapExtras($extras, static fn (TypeExpr $t): TypeExpr => self::resolved($t, $known, [])) ?? TypeExpr::openExtras(),
        );
    }

    /**
     * Every named type in a document sees every other (design/
     * shapes-and-case.md): each is compiled with its siblings standing as
     * `named`, and those are put in place once all are read -- so the
     * order of a map's entries, which DXN does not give, never matters.
     *
     * A schema or shape built from others (DXNS.md §4b) is built here too,
     * once the plain named types are compiled and before any is resolved:
     * its bases may be any schema or shape of the document, or one already
     * loaded. A built shape joins the named types; what was built for a
     * schema comes back beside the registry, by name.
     *
     * @param list<array{0: string, 1: mixed}> $aliasEntries
     * @param list<array{0: string, 1: mixed}> $schemaEntries
     * @return array{0: Registry, 1: array<string, Built>}
     */
    private static function compileAliases(array $aliasEntries, Registry $registry, array $schemaEntries): array
    {
        $scope = $registry->typeAliases();
        foreach ($aliasEntries as [$name]) {
            $scope[$name] = TypeExpr::named($name);
        }
        $compiled = [];
        $extending = [];
        foreach ($aliasEntries as [$name, $rawValue]) {
            if (self::isBuiltFrom($rawValue)) {
                $extending[] = [$name, $rawValue];
                continue;
            }
            try {
                $compiled[$name] = self::compileNamed($name, $rawValue, $scope);
            } catch (SchemaCompileError $err) {
                throw new SchemaCompileError("named type \"{$name}\": {$err->getMessage()}");
            }
        }
        $document = [];
        foreach ([...$aliasEntries, ...$schemaEntries] as [$name, $rawValue]) {
            $document[$name] = $rawValue;
        }
        $lookup = ['document' => $document, 'compiled' => $compiled, 'scope' => $scope, 'registry' => $registry];
        $built = [];
        foreach ([...$extending, ...$schemaEntries] as [$name, $rawValue]) {
            if (self::isBuiltFrom($rawValue)) {
                self::build($name, [], $lookup, $built);
            }
        }
        foreach ($extending as [$name, $rawValue]) {
            $shape = TypeExpr::shape($name, $built[$name]['fields'], $built[$name]['extras']);
            $compiled[$name] = $rawValue instanceof DXNTuple && self::isForm($rawValue, 'doc') && count($rawValue->items) === 3
                ? TypeExpr::doc($shape, self::annotations($rawValue->items[2], $shape))
                : $shape;
        }
        self::guarded($compiled);
        self::discriminators($compiled, [...$lookup, 'compiled' => $compiled], $built);
        $known = array_merge($registry->typeAliases(), $compiled);
        $result = $registry;
        foreach ($aliasEntries as [$name]) {
            try {
                $own = $compiled[$name];
                $typeExpr = $own->form === 'generic'
                    ? $own->with(['base' => self::resolved($own->base ?? TypeExpr::any(), [...$known, ...self::paramBounds($own->params ?? [])], [$name])])
                    : self::resolved($own, $known, [$name]);
            } catch (SchemaCompileError $err) {
                throw new SchemaCompileError("named type \"{$name}\": {$err->getMessage()}");
            }
            $result = $result->putTypeVersion($name, self::$versions[$name] ?? 1, $typeExpr);
        }
        return [$result, $built];
    }

    // ---- discriminators (G3 of dextrin's design/typespec.md) -----------------

    /**
     * Every variant under a `discriminator` has that field: the field a
     * value's variant is chosen by. Each variant is a schema or a shape.
     *
     * @param array<string, TypeExpr> $compiled
     * @param Lookup $lookup
     * @param array<string, Built> $built
     */
    private static function discriminators(array $compiled, array $lookup, array $built): void
    {
        ksort($compiled, SORT_STRING);
        foreach ($compiled as $name => $type) {
            foreach (self::discriminated($type) as [$field, $variants]) {
                foreach ($variants as $variant) {
                    $names = self::variantFields($variant, $lookup, $built, []);
                    if ($names === null) {
                        throw new SchemaCompileError(
                            "\"{$name}\": its discriminator {$field} needs every variant to be a schema or a shape; "
                            . self::variantLabel($variant) . ' is not',
                        );
                    }
                    if (!in_array($field, $names, true)) {
                        throw new SchemaCompileError("\"{$name}\": its discriminator {$field} is no field of " . self::variantLabel($variant));
                    }
                }
            }
        }
    }

    /**
     * Every `{:doc {:any-of ...} %{discriminator: f}}` in `$type`, as
     * `[f, variants]`: through every type it holds, a shape's fields too,
     * but a refine's constraints.
     *
     * @return list<array{0: string, 1: list<TypeExpr>}>
     */
    private static function discriminated(TypeExpr $type): array
    {
        $own = [];
        if ($type->form === 'doc' && is_string($type->notes['discriminator'] ?? null)) {
            $own[] = [$type->notes['discriminator'], self::variantList($type->elem ?? TypeExpr::any())];
        }
        $children = array_values(array_filter(
            [$type->elem, $type->base, $type->key, $type->value, $type->otherwise],
            static fn (?TypeExpr $t): bool => $t !== null,
        ));
        $bounds = [];
        foreach ($type->params ?? [] as $param) {
            if (is_array($param)) {
                $bounds = [...$bounds, $param[1], ...($param[2] === null ? [] : [$param[2]])];
            }
        }
        $children = [
            ...$children,
            ...($type->elems ?? []),
            ...($type->variants ?? []),
            ...array_map(static fn (array $b): TypeExpr => $b[1], $type->branches ?? []),
            ...array_merge([], ...array_map(static fn (Particle $p): array => $p->types(), $type->particles ?? [])),
            ...array_map(static fn (Field $f): TypeExpr => $f->type, $type->fields ?? []),
            ...TypeExpr::extrasTypes($type->extras),
            ...$bounds,
        ];
        return array_merge($own, ...array_map(self::discriminated(...), $children));
    }

    /** @return list<TypeExpr> */
    private static function variantList(TypeExpr $type): array
    {
        return $type->form === 'doc' ? self::variantList($type->elem ?? TypeExpr::any()) : ($type->variants ?? []);
    }

    /**
     * The names of a variant's fields: a shape's, or a schema's of the
     * document or one loaded; `null` for any other variant.
     *
     * @param Lookup $lookup
     * @param array<string, Built> $built
     * @param list<string> $seen
     * @return list<string>|null
     */
    private static function variantFields(TypeExpr $variant, array $lookup, array $built, array $seen): ?array
    {
        /** @param list<Field> $fields @return list<string> */
        $names = static fn (array $fields): array => array_values(array_map(static fn (Field $f): string => $f->name, $fields));
        switch ($variant->form) {
            case 'doc':
                return self::variantFields($variant->elem ?? TypeExpr::any(), $lookup, $built, $seen);
            case 'shape':
                return $names($variant->fields ?? []);
            case 'named':
                $name = (string) $variant->name;
                $type = array_merge($lookup['registry']->typeAliases(), $lookup['compiled'])[$name] ?? null;
                return $type === null || in_array($name, $seen, true) ? null : self::variantFields($type, $lookup, $built, [$name, ...$seen]);
            case 'reference':
                $name = (string) $variant->name;
                // A variant pinned to a version (G11): that version's
                // fields when it is loaded, otherwise judged by its name as
                // an unpinned one is.
                $pinned = $variant->version === null ? null : $lookup['registry']->fetchStructSchema($name, $variant->version);
                if ($pinned !== null) {
                    return $names($pinned->compiled->fields);
                }
                if (isset($built[$name])) {
                    return $names($built[$name]['fields']);
                }
                $raw = self::undoc($lookup['document'][$name] ?? null);
                if ($raw instanceof DXNStruct && $raw->name === 'schema' && $raw->isKeyed()) {
                    try {
                        return $names(self::compileEntry($raw, $lookup['scope'])->fields);
                    } catch (SchemaCompileError) {
                        return null;
                    }
                }
                $found = $lookup['registry']->fetchStructSchema($name);
                return $found === null ? null : $names($found->compiled->fields);
            default:
                return null;
        }
    }

    private static function variantLabel(TypeExpr $variant): string
    {
        return match ($variant->form) {
            'doc' => self::variantLabel($variant->elem ?? TypeExpr::any()),
            // A pinned variant is named by its pin (G11): `Pet(1)`.
            'reference' => $variant->version === null ? (string) $variant->name : DXNTuple::pinnedText((string) $variant->name, $variant->version),
            'named' => (string) $variant->name,
            default => 'an inline variant',
        };
    }

    // ---- built from others (DXNS.md §4b) ------------------------------------

    /**
     * Whether a document's entry is a `%schema` or `%shape` built from
     * others. `- *` and `* K => V` (DXNS.md §4c) do not make it built.
     */
    private static function isBuiltFrom(mixed $entry): bool
    {
        $raw = self::undoc($entry);
        return $raw instanceof DXNStruct && ($raw->name === 'schema' || $raw->name === 'shape') && $raw->isBuilt();
    }

    /**
     * The fields of the document's entry `$name`, built from its bases,
     * kept in `$built` with the bases as {@see Compiled} records them.
     * `$path` holds the entries being built, so one that reaches itself is
     * seen.
     *
     * @param list<string> $path
     * @param Lookup $lookup
     * @param array<string, Built> $built
     */
    private static function build(string $name, array $path, array $lookup, array &$built): void
    {
        if (isset($built[$name])) {
            return;
        }
        if (in_array($name, $path, true)) {
            throw new SchemaCompileError("\"{$name}\" is built from itself");
        }
        $raw = self::undoc($lookup['document'][$name]);
        if (!$raw instanceof DXNStruct) {
            throw new \LogicException('only a struct is built from others');
        }
        if ($raw->bases === []) {
            throw new SchemaCompileError("\"{$name}\" drops a field, but names no base to drop it from");
        }
        if ($raw->name === 'shape' && $raw->version !== null) {
            throw new SchemaCompileError('a shape has no version: %shape extends Base {field: type, ...}');
        }
        if (!$raw->isKeyed()) {
            throw new SchemaCompileError("a {$raw->name} lists its fields by name: %{$raw->name}(N){field: type, ...}");
        }
        self::distinctBases($name, $raw->bases);
        $own = self::ownFields($name, $raw, $lookup['scope']);
        try {
            $ownExtras = self::ownExtras($raw, $lookup['scope']);
        } catch (SchemaCompileError $err) {
            throw new SchemaCompileError("\"{$name}\": {$err->getMessage()}");
        }
        $found = self::foundBases($name, $raw->bases, [$name, ...$path], $lookup, $built);
        // A base of this document still names its sibling types, one
        // already loaded has them in place: two fields compare with theirs
        // in place.
        $known = array_merge($lookup['registry']->typeAliases(), $lookup['compiled']);
        $same = static fn (Field $a, Field $b): bool => self::comparable($a, $known) == self::comparable($b, $known);
        try {
            $fields = Bases::merge(
                array_map(static fn (array $b): array => ['kind' => $b['kind'], 'name' => $b['name'], 'fields' => $b['fields']], $found),
                $own,
                $raw->fieldDrops(),
                $same,
            );
        } catch (SchemaCompileError $err) {
            throw new SchemaCompileError("\"{$name}\": {$err->getMessage()}");
        }
        $extras = self::mergedExtras($name, $found, $own, $ownExtras, $known);
        self::builtCases($name, $fields);
        $built[$name] = [
            'fields' => $fields,
            'bases' => array_map(static fn (array $b): array => [$b['kind'], $b['name'], $b['version']], $found),
            'extras' => $extras,
        ];
    }

    /**
     * What a body says it takes beyond its fields (DXNS.md §4c): its
     * `* K => V` entries, each compiled in `$scope`, and whether `- *`
     * closes it; `null` when it says nothing.
     *
     * @param array<string, TypeExpr> $scope
     * @return Extras|null
     */
    private static function ownExtras(DXNStruct $raw, array $scope): ?array
    {
        if ($raw->rest === [] && !$raw->isClosed()) {
            return null;
        }
        return [
            'entries' => array_map(
                static fn (array $e): array => [self::compileTypeExpr($e[0], $scope), self::compileTypeExpr($e[1], $scope)],
                $raw->rest,
            ),
            'closed' => $raw->isClosed(),
        ];
    }

    /**
     * A built body's extras (DXNS.md §4c): its own when it says; otherwise
     * what its bases say, which must agree -- a base that says nothing is
     * open and agrees with any. What `extends` a closed base can pass as
     * it only when it says what the base says and adds no field.
     *
     * @param list<array{kind: 'extends'|'includes', name: string, fields: list<Field>, extras: Extras, version: ?int}> $found
     * @param list<Field> $own
     * @param Extras|null $ownExtras
     * @param array<string, TypeExpr> $known
     * @return Extras
     */
    private static function mergedExtras(string $name, array $found, array $own, ?array $ownExtras, array $known): array
    {
        $said = [];
        foreach ($found as $base) {
            if ($base['extras'] == TypeExpr::openExtras()) {
                continue;
            }
            $key = serialize(TypeExpr::mapExtras($base['extras'], static fn (TypeExpr $t): TypeExpr => self::inPlace($t, $known, [])));
            $said[$key] ??= $base['extras'];
        }
        foreach ($found as $base) {
            if ($base['kind'] !== 'extends' || !$base['extras']['closed']) {
                continue;
            }
            $baseNames = array_map(static fn (Field $f): string => $f->name, $base['fields']);
            $added = array_filter($own, static fn (Field $f): bool => !in_array($f->name, $baseNames, true));
            if ($added !== [] || ($ownExtras !== null && serialize($ownExtras) !== serialize($base['extras']))) {
                throw new SchemaCompileError(
                    "\"{$name}\" extends {$base['name']}, which is closed (- *): "
                    . "it adds no field and no extras, or includes {$base['name']} instead",
                );
            }
            break;
        }
        if ($ownExtras !== null) {
            return $ownExtras;
        }
        if (count($said) > 1) {
            throw new SchemaCompileError("\"{$name}\": its bases differ in their extras (- * or * K => V); say which in its body");
        }
        return $said === [] ? TypeExpr::openExtras() : array_values($said)[0];
    }

    /**
     * `$field` as it compares with the same field found another way: its
     * type with every named type `$known` has in place.
     *
     * @param array<string, TypeExpr> $known
     */
    private static function comparable(Field $field, array $known): Field
    {
        return new Field($field->name, $field->required, self::inPlace($field->type, $known, []), $field->default, $field->description);
    }

    /**
     * `$type` with each `named` replaced by its definition in `$known`;
     * one being replaced already (`$path`), or one `$known` lacks, stays
     * a reference by name.
     *
     * @param array<string, TypeExpr> $known
     * @param list<string> $path
     */
    private static function inPlace(TypeExpr $type, array $known, array $path): TypeExpr
    {
        if ($type->form === 'named') {
            $name = $type->name ?? '';
            $target = $known[$name] ?? null;
            return $target === null || in_array($name, $path, true) ? $type : self::inPlace($target, $known, [$name, ...$path]);
        }
        $one = static fn (?TypeExpr $t): ?TypeExpr => $t === null ? null : self::inPlace($t, $known, $path);
        $many = static fn (?array $ts): ?array => $ts === null ? null : array_map(static fn (TypeExpr $t): TypeExpr => self::inPlace($t, $known, $path), $ts);
        return $type->with([
            'elem' => $one($type->elem),
            'base' => $one($type->base),
            'key' => $one($type->key),
            'value' => $one($type->value),
            'otherwise' => $one($type->otherwise),
            'elems' => $many($type->elems),
            'variants' => $many($type->variants),
            'fields' => $type->fields === null ? null : array_map(static fn (Field $f): Field => self::comparable($f, $known), $type->fields),
            'extras' => TypeExpr::mapExtras($type->extras, static fn (TypeExpr $t): TypeExpr => self::inPlace($t, $known, $path)),
            'branches' => $type->branches === null ? null : array_map(static fn (array $b): array => [$b[0], self::inPlace($b[1], $known, $path)], $type->branches),
            'particles' => $type->particles === null ? null : array_map(
                static fn (Particle $p): Particle => $p->map(static fn (TypeExpr $t): TypeExpr => self::inPlace($t, $known, $path)),
                $type->particles,
            ),
            'constraints' => $type->constraints === null ? null : TypeExpr::withTypes($type->constraints, static fn (TypeExpr $t): TypeExpr => self::inPlace($t, $known, $path)),
        ]);
    }

    /** @param list<array{0: 'extends'|'includes', 1: string, 2: ?int}> $bases */
    private static function distinctBases(string $name, array $bases): void
    {
        foreach (array_count_values(array_column($bases, 1)) as $base => $count) {
            if ($count > 1) {
                throw new SchemaCompileError("\"{$name}\" names {$base} twice in its head");
            }
        }
    }

    /**
     * The fields a built entry's body declares itself, compiled in the
     * document's scope; whether a `{:case}` among them names a field is
     * told once they are merged.
     *
     * @param array<string, TypeExpr> $scope
     * @return list<Field>
     */
    private static function ownFields(string $name, DXNStruct $raw, array $scope): array
    {
        self::notFormat10($raw->pairs());
        $fields = [];
        $seen = [];
        foreach ($raw->pairs() as [$key, $value]) {
            try {
                $field = self::compileField($key, $value, $scope);
            } catch (SchemaCompileError $err) {
                throw new SchemaCompileError("\"{$name}\": field \"{$key}\": {$err->getMessage()}");
            }
            if (isset($seen[$field->name])) {
                throw new SchemaCompileError("\"{$name}\": field \"{$field->name}\" is named twice");
            }
            $seen[$field->name] = true;
            $fields[] = $field;
        }
        return $fields;
    }

    /**
     * A `{:case}` in a field names a field of the result (DXNS.md §4b), so
     * one whose field was dropped, or that no base gives, is refused.
     *
     * @param list<Field> $fields
     */
    private static function builtCases(string $name, array $fields): void
    {
        $names = array_map(static fn (Field $f): string => $f->name, $fields);
        foreach ($fields as $field) {
            foreach (self::caseNames($field->type) as $missing) {
                if (!in_array($missing, $names, true)) {
                    throw new SchemaCompileError("\"{$name}\": {:case {$missing} ...} names no field of it");
                }
            }
        }
    }

    /**
     * The sibling fields every `{:case}` in `$type` names; a shape's cases
     * name its own fields, so it is not looked into.
     *
     * @return list<string>
     */
    private static function caseNames(TypeExpr $type): array
    {
        if ($type->form === 'shape') {
            return [];
        }
        $children = array_values(array_filter(
            [$type->elem, $type->base, $type->key, $type->value, $type->otherwise],
            static fn (?TypeExpr $t): bool => $t !== null,
        ));
        $children = [
            ...$children,
            ...($type->variants ?? []),
            ...($type->elems ?? []),
            ...array_map(static fn (array $b): TypeExpr => $b[1], $type->branches ?? []),
            ...array_merge([], ...array_map(static fn (Particle $p): array => $p->types(), $type->particles ?? [])),
            ...TypeExpr::heldTypes($type->constraints ?? []),
        ];
        $names = $type->form === 'case' ? [(string) $type->name] : [];
        return array_merge($names, ...array_map(self::caseNames(...), $children));
    }

    /**
     * Each base of the head, found: its fields, its extras (DXNS.md §4c)
     * and the version it was taken at. A base of the document that is
     * itself built is built first.
     *
     * @param list<array{0: 'extends'|'includes', 1: string, 2: ?int}> $bases
     * @param list<string> $path
     * @param Lookup $lookup
     * @param array<string, Built> $built
     * @return list<array{kind: 'extends'|'includes', name: string, fields: list<Field>, extras: Extras, version: ?int}>
     */
    private static function foundBases(string $name, array $bases, array $path, array $lookup, array &$built): array
    {
        $found = [];
        foreach ($bases as [$kind, $base, $version]) {
            $full = self::baseTarget(self::$names[$base] ?? $base, $lookup, []);
            $generic = self::genericUse($full, $lookup);
            $answer = match (true) {
                $generic !== null && $version === null => [$generic, null],
                $generic !== null => "a shape has no versions, so {$full}({$version}) names none",
                array_key_exists($full, $lookup['document']) => self::documentBase($full, self::undoc($lookup['document'][$full]), $version, $path, $lookup, $built),
                default => self::loadedBase($full, $version, $lookup['registry']),
            };
            if (is_string($answer)) {
                throw new SchemaCompileError("\"{$name}\": base {$base}: {$answer}");
            }
            $found[] = ['kind' => $kind, 'name' => $full, 'fields' => $answer[0][0], 'extras' => $answer[0][1], 'version' => $answer[1]];
        }
        return $found;
    }

    /**
     * A base named by a named type that stands for a schema or a shape
     * (`Human: Person`) is that schema or shape (G6 of dextrin's
     * design/typespec.md): its name, followed through such names.
     *
     * @param Lookup $lookup
     * @param list<string> $seen
     */
    private static function baseTarget(string $name, array $lookup, array $seen): string
    {
        $raw = self::undoc($lookup['document'][$name] ?? null);
        if (($raw instanceof DXNStruct && ($raw->name === 'schema' || $raw->name === 'shape')) || in_array($name, $seen, true)) {
            return $name;
        }
        $type = array_merge($lookup['registry']->typeAliases(), $lookup['compiled'])[$name] ?? null;
        while ($type !== null && $type->form === 'doc') {
            $type = $type->elem;
        }
        return $type !== null && ($type->form === 'reference' || $type->form === 'named')
            ? self::baseTarget((string) $type->name, $lookup, [$name, ...$seen])
            : $name;
    }

    /**
     * A named type that is one use of a generic standing for a shape
     * (`PageOfInt: {:of Page :integer}`): that shape's fields, its
     * defaults filled, and its extras, or `null`.
     *
     * @param Lookup $lookup
     * @return Body|null
     */
    private static function genericUse(string $name, array $lookup): ?array
    {
        $known = array_merge($lookup['registry']->typeAliases(), $lookup['compiled']);
        $type = self::undocType($known[$name] ?? null);
        if ($type === null || $type->form !== 'apply') {
            return null;
        }
        $generic = self::undocType($known[$type->name ?? ''] ?? null);
        if ($generic === null || $generic->form !== 'generic') {
            return null;
        }
        try {
            $args = self::filledArgs((string) $type->name, $type->elems ?? [], $known);
        } catch (SchemaCompileError) {
            return null;
        }
        return self::shapeBody(TypeExpr::instantiate($generic->params ?? [], $generic->base ?? TypeExpr::any(), $args));
    }

    private static function undocType(?TypeExpr $type): ?TypeExpr
    {
        return $type !== null && $type->form === 'doc' ? self::undocType($type->elem) : $type;
    }

    /**
     * A base of the document: its fields and extras, and the version they
     * are at, or why it is none.
     *
     * @param list<string> $path
     * @param Lookup $lookup
     * @param array<string, Built> $built
     * @return array{0: Body, 1: ?int}|string
     */
    private static function documentBase(string $base, mixed $raw, ?int $version, array $path, array $lookup, array &$built): array|string
    {
        if (!$raw instanceof DXNStruct || ($raw->name !== 'schema' && $raw->name !== 'shape')) {
            return 'a base is a %schema or a %shape';
        }
        if ($raw->name === 'shape' && $version !== null) {
            return "a shape has no versions, so {$base}({$version}) names none";
        }
        if (self::isBuiltFrom($raw)) {
            self::build($base, $path, $lookup, $built);
            return self::atVersion($base, $raw, $version, [$built[$base]['fields'], $built[$base]['extras']], $lookup['registry']);
        }
        if ($raw->name === 'shape') {
            return [self::shapeBody($lookup['compiled'][$base]) ?? [[], TypeExpr::openExtras()], null];
        }
        try {
            $compiled = self::compileEntry($raw, $lookup['scope']);
        } catch (SchemaCompileError $err) {
            return $err->getMessage();
        }
        return self::atVersion($base, $raw, $version, [$compiled->fields, $compiled->extras], $lookup['registry']);
    }

    /**
     * A schema of the document, at the version the head asks for: its
     * own, or one already loaded. Without a version, the latest of them --
     * what is loaded asked for as any other base is, so a resolver's
     * answer counts.
     *
     * @param Body $body
     * @return array{0: Body, 1: ?int}|string
     */
    private static function atVersion(string $base, DXNStruct $raw, ?int $version, array $body, Registry $registry): array|string
    {
        if ($raw->name === 'shape') {
            return [$body, null];
        }
        $own = $raw->version ?? 1;
        $loaded = $registry->fetchStructSchema($base)?->compiled->version;
        $wanted = $version ?? max($own, $loaded ?? $own);
        return $wanted === $own ? [$body, $own] : self::loadedBase($base, $wanted, $registry);
    }

    /**
     * A base already loaded: a schema at `$version`, or its latest
     * without one; or a shape among the named types.
     *
     * @return array{0: Body, 1: ?int}|string
     */
    private static function loadedBase(string $base, ?int $version, Registry $registry): array|string
    {
        $found = $registry->fetchStructSchema($base, $version);
        if ($found !== null) {
            return [[$found->compiled->fields, $found->compiled->extras], $found->compiled->version];
        }
        $type = $registry->fetchTypeAlias($base);
        if ($version !== null) {
            return "{$base}({$version}) is not loaded";
        }
        if ($type === null) {
            return "no schema or shape is named {$base}";
        }
        $shape = self::shapeBody($type);
        return $shape === null ? 'a base is a %schema or a %shape' : [$shape, null];
    }

    /**
     * A shape's fields and extras, as a base gives them, or `null` for any
     * other type.
     *
     * @return Body|null
     */
    private static function shapeBody(?TypeExpr $type): ?array
    {
        return match ($type?->form) {
            'shape' => [$type->fields ?? [], $type->extras ?? TypeExpr::openExtras()],
            'doc' => self::shapeBody($type->elem),
            default => null,
        };
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
            if (count($raw->items) !== 3 || $list === null) {
                throw new SchemaCompileError("a generic's parameters are symbols: {:generic [t ...] body}");
            }
            $params = self::parameters(array_values($list), $scope);
            $names = array_map(TypeExpr::paramName(...), $params);
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
            return TypeExpr::generic($params, self::compileNamed($name, $body, [...$scope, ...self::paramBounds($params)]));
        }
        $type = self::compileTypeExpr($raw, $scope);
        return $type->form === 'shape' && $type->name === null ? $type->with(['name' => $name]) : $type;
    }

    /**
     * `{:generic [t ...] body}`'s parameters, distinct: a bare symbol, or
     * `{t bound}` or `{t bound default}` (G2 of dextrin's
     * design/typespec.md), the bound and default compiled in the scope
     * around the generic. A bare parameter stays its name; one with a
     * bound is `[name, bound, default]`, `null` for no default. A
     * parameter without a default follows none with one, and a default
     * is within its own bound.
     *
     * @param list<mixed> $list
     * @param array<string, TypeExpr> $scope
     * @return list<string|array{0: string, 1: TypeExpr, 2: ?TypeExpr}>
     */
    private static function parameters(array $list, array $scope): array
    {
        $params = array_map(static fn (mixed $p): string|array => self::parameter($p, $scope), $list);
        $names = array_map(TypeExpr::paramName(...), $params);
        if ($params === []) {
            throw new SchemaCompileError("a generic's parameters are symbols: {:generic [t ...] body}");
        }
        if (count(array_unique($names)) !== count($names)) {
            throw new SchemaCompileError('a generic names a parameter twice');
        }
        $defaulted = false;
        foreach ($params as $param) {
            $hasDefault = is_array($param) && $param[2] !== null;
            if ($defaulted && !$hasDefault) {
                throw new SchemaCompileError("a generic's parameter without a default cannot follow one with a default");
            }
            $defaulted = $defaulted || $hasDefault;
        }
        foreach ($params as $param) {
            if (is_array($param) && $param[2] !== null && !self::withinBound($param[2], $param[1], $scope)) {
                throw new SchemaCompileError("{$param[0]}'s default is outside its own bound");
            }
        }
        return $params;
    }

    /**
     * @param array<string, TypeExpr> $scope
     * @return string|array{0: string, 1: TypeExpr, 2: ?TypeExpr}
     */
    private static function parameter(mixed $param, array $scope): string|array
    {
        if ($param instanceof DXNSymbol) {
            return $param->name;
        }
        $items = $param instanceof DXNTuple ? $param->items : [];
        if (($items[0] ?? null) instanceof DXNSymbol && (count($items) === 2 || count($items) === 3)) {
            return [
                $items[0]->name,
                self::compileTypeExpr($items[1], $scope),
                count($items) === 3 ? self::compileTypeExpr($items[2], $scope) : null,
            ];
        }
        throw new SchemaCompileError("a generic's parameter is a symbol, {t bound} or {t bound default}, not " . self::inspect($param));
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
                /** @var list<TypeExpr> $args */
                $args = $many($type->elems) ?? [];
                return $type->with(['elems' => self::filledArgs($type->name ?? '', $args, $known)]);
            case 'generic':
                throw new SchemaCompileError("a generic is a named type's whole definition, {:generic [t ...] body}");
            case 'shape':
                return $type->with([
                    'fields' => array_map(
                        static fn (Field $f): Field => new Field($f->name, $f->required, self::resolved($f->type, $known, $path), $f->default, $f->description),
                        $type->fields ?? [],
                    ),
                    'extras' => TypeExpr::mapExtras($type->extras, static fn (TypeExpr $t): TypeExpr => self::resolved($t, $known, $path)),
                ]);
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
     * `$args` for the generic `$name`: every parameter given or defaulted,
     * the defaults filled in, each argument within its parameter's bound
     * (G2 of dextrin's design/typespec.md). A sibling not yet compiled
     * takes them as they are; they are filled once it is.
     *
     * @param list<TypeExpr> $args
     * @param array<string, TypeExpr> $known
     * @return list<TypeExpr>
     */
    private static function filledArgs(string $name, array $args, array $known): array
    {
        $target = $known[$name] ?? null;
        if ($target !== null && $target->form === 'named') {
            return $args;
        }
        if ($target === null || $target->form !== 'generic') {
            throw new SchemaCompileError("{:of {$name} ...} names no generic");
        }
        $params = $target->params ?? [];
        $given = count($args);
        $required = count(array_filter($params, static fn (string|array $p): bool => !is_array($p) || $p[2] === null));
        if ($given < $required || $given > count($params)) {
            $arity = $required === count($params) ? (string) $required : "{$required} to " . count($params);
            throw new SchemaCompileError("{$name} takes {$arity} type argument(s), {:of {$name} ...} gives {$given}");
        }
        $filled = $args;
        foreach (array_slice($params, $given) as $param) {
            // Only a parameter with a default is past the arguments given.
            if (is_array($param) && $param[2] !== null) {
                $filled[] = $param[2];
            }
        }
        foreach ($params as $i => $param) {
            if (is_array($param) && !self::withinBound($filled[$i], $param[1], $known)) {
                throw new SchemaCompileError(
                    "{:of {$name} ...}'s {$param[0]} takes what its bound takes; " . self::inspectArg($filled[$i]) . ' is outside it'
                );
            }
        }
        return $filled;
    }

    /**
     * A generic's parameters' bounds, `:any` for a bare one, under the
     * scope keys a use inside its body reads them by.
     *
     * @param list<string|array{0: string, 1: TypeExpr, 2: ?TypeExpr}> $params
     * @return array<string, TypeExpr>
     */
    private static function paramBounds(array $params): array
    {
        $bounds = [];
        foreach ($params as $param) {
            $bounds[self::boundKey(TypeExpr::paramName($param))] = is_array($param) ? $param[1] : TypeExpr::any();
        }
        return $bounds;
    }

    /**
     * The scope key the parameter `$name`'s bound travels under: no name
     * a document can write, so it never stands for a type.
     */
    private static function boundKey(string $name): string
    {
        return "\0bound:{$name}";
    }

    /** An argument as a message names it. */
    private static function inspectArg(TypeExpr $arg): string
    {
        return match ($arg->form) {
            'named', 'reference', 'param' => (string) $arg->name,
            'primitive' => ':' . $arg->name,
            'any', 'never' => ':' . $arg->form,
            default => $arg->form,
        };
    }

    /**
     * Whether `$arg` is `$bound`, or narrower: a refine or annotation of
     * it, a name standing for it, a const or enum whose values it takes,
     * or a schema that extends it. Nothing broader is worked out: DXNS
     * has no general subtyping, so anything else is outside.
     *
     * @param array<string, TypeExpr> $known
     */
    private static function withinBound(TypeExpr $arg, TypeExpr $bound, array $known): bool
    {
        // A sibling not yet compiled passes for now: resolution checks
        // again, through filledArgs(), with every definition in hand.
        return self::pending($arg, $known) || self::pending($bound, $known) || self::within($arg, $bound, $known);
    }

    /**
     * Whether `$type` names a sibling of the document not yet compiled:
     * the scope holds it only as its own name.
     *
     * @param array<string, TypeExpr> $known
     */
    private static function pending(TypeExpr $type, array $known): bool
    {
        $target = $type->form === 'named' ? ($known[$type->name ?? ''] ?? null) : null;
        return $target !== null && $target->form === 'named' && $target->name === $type->name;
    }

    /**
     * {@see self::withinBound()} once neither side is pending.
     *
     * @param array<string, TypeExpr> $known
     */
    private static function within(TypeExpr $arg, TypeExpr $bound, array $known): bool
    {
        if ($bound->form === 'any' || serialize($arg) === serialize($bound)) {
            return true;
        }
        if ($arg->form === 'doc') {
            return self::withinBound($arg->elem ?? TypeExpr::any(), $bound, $known);
        }
        if ($bound->form === 'doc') {
            return self::withinBound($arg, $bound->elem ?? TypeExpr::any(), $known);
        }
        switch ($arg->form) {
            case 'refine':
                return self::withinBound($arg->base ?? TypeExpr::any(), $bound, $known);
            case 'enum':
                $resolved = self::boundResolved($bound, $known);
                foreach ($arg->literals ?? [] as $literal) {
                    if (!$resolved->matches($literal)) {
                        return false;
                    }
                }
                return true;
            case 'param':
                // A parameter passed on is within a bound when its own is.
                $own = $known[self::boundKey((string) $arg->name)] ?? null;
                return $own !== null && self::withinBound($own, $bound, $known);
            case 'named':
                $target = $known[$arg->name ?? ''] ?? null;
                return $target !== null && !($target->form === 'named' && $target->name === $arg->name)
                    && self::withinBound($target, $bound, $known);
        }
        // A pinned reference is within what its schema is within (G11).
        if ($arg->form === 'reference' && $arg->version !== null) {
            return self::withinBound(TypeExpr::reference((string) $arg->name), $bound, $known);
        }
        if ($arg->form === 'reference' && $bound->form === 'reference') {
            return self::extends((string) $arg->name, (string) $bound->name, []);
        }
        // A bound naming a type: what that type is.
        if ($bound->form === 'named') {
            $target = $known[$bound->name ?? ''] ?? null;
            return $target !== null && !($target->form === 'named' && $target->name === $bound->name)
                && self::withinBound($arg, $target, $known);
        }
        return false;
    }

    /** @param array<string, TypeExpr> $known */
    private static function boundResolved(TypeExpr $bound, array $known): TypeExpr
    {
        if ($bound->form !== 'named') {
            return $bound;
        }
        $target = $known[$bound->name ?? ''] ?? null;
        return $target === null || ($target->form === 'named' && $target->name === $bound->name) ? $bound : self::boundResolved($target, $known);
    }

    /**
     * Whether the schema `$name` extends `$base`, directly or through
     * others, in this document or as loaded.
     *
     * @param list<string> $seen
     */
    private static function extends(string $name, string $base, array $seen): bool
    {
        if ($name === $base) {
            return true;
        }
        if (in_array($name, $seen, true)) {
            return false;
        }
        foreach (self::extendedBases($name) as $next) {
            if (self::extends($next, $base, [$name, ...$seen])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The bases the schema `$name` extends, as its head writes them, or
     * as the loaded schema records them.
     *
     * @return list<string>
     */
    private static function extendedBases(string $name): array
    {
        $raw = self::undoc(self::$document[$name] ?? null);
        if ($raw instanceof DXNStruct && $raw->name === 'schema') {
            $bases = $raw->bases;
        } else {
            $bases = self::$loaded?->fetchStructSchema($name)?->compiled->bases ?? [];
        }
        $names = [];
        foreach ($bases as [$kind, $base]) {
            if ($kind === 'extends') {
                $names[] = $base;
            }
        }
        return $names;
    }

    private static function takesArguments(string $name, TypeExpr $generic): string
    {
        return "{$name} is generic: write {:of {$name} " . implode(' ', array_map(TypeExpr::paramName(...), $generic->params ?? [])) . '}, with a type for each';
    }

    /**
     * `Pet(1)`, which DXN reads as `{:at Pet 1}` (G11, DXNS.md §5c): a name
     * pinned to one version. A named type's version is put in place -- a
     * sibling at that version stays by name -- and a version of a named
     * type that is not loaded is a compile error; any other name is a
     * schema's, checked when a value meets it.
     *
     * @param array<string, TypeExpr> $aliases
     */
    private static function pinnedType(DXNTuple $value, array $aliases): TypeExpr
    {
        $pinned = DXNTuple::pinned($value);
        if ($pinned === null) {
            throw new SchemaCompileError('a pinned name is Name(N), {:at Name N}, N a positive integer');
        }
        [$written, $version] = $pinned;
        $name = self::fullName($written, $aliases);
        $inScope = $aliases[$name] ?? null;
        $sibling = $inScope !== null && $inScope->form === 'named' && $inScope->name === $name;
        if ($sibling && (self::$versions[$name] ?? null) === $version) {
            return $inScope;
        }
        $loaded = self::$pinSource?->fetchTypeVersion($name, $version);
        if ($loaded !== null) {
            return $loaded;
        }
        $namedType = $inScope !== null || isset(self::$pinSource?->typeVersions()[$name]);
        if ($namedType) {
            throw new SchemaCompileError(DXNTuple::pinnedText($name, $version) . ' is not loaded');
        }
        return TypeExpr::reference($name, $version);
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
        return new Compiled(fields: $fields, version: $entry->version ?? 1, extras: self::ownExtras($entry, $aliases) ?? TypeExpr::openExtras());
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
            $default = self::fieldDefault(
                self::hasField($fieldPairs, 'default') ? self::getField($fieldPairs, 'default') : Field::NO_DEFAULT,
                self::hasField($fieldPairs, 'computed') ? self::getField($fieldPairs, 'computed') : Field::NO_DEFAULT,
                $type,
                $aliases,
            );
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

    /**
     * A field's default: the value written, or one computed when the
     * field is filled (G8 of dextrin's design/typespec.md). `computed:
     * :now` takes a date, time, datetime or local date-time, possibly
     * refined, annotated or nilable, and never stands beside a `default`.
     *
     * @param array<string, TypeExpr> $aliases
     */
    private static function fieldDefault(mixed $default, mixed $computed, TypeExpr $type, array $aliases): mixed
    {
        if ($computed === Field::NO_DEFAULT) {
            return $default;
        }
        if ($default !== Field::NO_DEFAULT) {
            throw new SchemaCompileError('a %field has a default or is computed, not both');
        }
        if (self::literalName($computed) !== 'now') {
            throw new SchemaCompileError('a %field is computed: :now, the one value there is; not ' . self::inspect($computed));
        }
        $kind = self::temporalKind($type, $aliases);
        if ($kind === null) {
            throw new SchemaCompileError(
                'a %field computed: :now is a date, time, datetime or local-datetime, not ' . self::inspectType($type),
            );
        }
        return new Computed($kind);
    }

    /**
     * The temporal type `$type` is, through refines, docs, nilables and
     * names, or `null`.
     *
     * @param array<string, TypeExpr> $aliases
     * @return 'date'|'time'|'datetime'|'local-datetime'|null
     */
    private static function temporalKind(TypeExpr $type, array $aliases): ?string
    {
        switch ($type->form) {
            case 'primitive':
                return match ($type->name) {
                    'date' => 'date',
                    'time' => 'time',
                    'datetime' => 'datetime',
                    'local-datetime' => 'local-datetime',
                    default => null,
                };
            case 'refine':
                return self::temporalKind($type->base ?? TypeExpr::any(), $aliases);
            case 'doc':
            case 'nilable':
                return self::temporalKind($type->elem ?? TypeExpr::any(), $aliases);
            case 'named':
                $target = $aliases[$type->name ?? ''] ?? null;
                return $target === null || ($target->form === 'named' && $target->name === $type->name)
                    ? null
                    : self::temporalKind($target, $aliases);
            default:
                return null;
        }
    }

    /** A type as a message names it: its form, `:any` or `:never`. */
    private static function inspectType(?TypeExpr $type): string
    {
        return match ($type?->form) {
            null => 'a schema',
            'any', 'never' => ':' . $type->form,
            default => $type->form,
        };
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
            if ($value->isBuilt()) {
                throw new SchemaCompileError(
                    'a shape built from others is an entry of its own, Name: %shape extends Base {...}, used by its name',
                );
            }
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
            return TypeExpr::shape(null, $fields, self::ownExtras($value, $aliases));
        }

        if ($value instanceof DXNTuple) {
            $items = $value->items;
            $head = $items[0] ?? null;
            $tag = $head instanceof DXNKeyword ? $head->name : null;
            $rest = array_slice($items, 1);

            if ($tag === 'at') {
                return self::pinnedType($value, $aliases);
            }

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
                $args = array_map(
                    static fn (mixed $v): TypeExpr => self::compileTypeExpr($v, $aliases),
                    array_slice($rest, 1),
                );
                return TypeExpr::apply($name, self::filledArgs($name, $args, $aliases));
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
        'int8' => [-128, 127],
        'int16' => [-32_768, 32_767],
        'uint8' => [0, 255],
        'uint16' => [0, 65_535],
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

    private const ANNOTATION_KEYS = [
        'title', 'description', 'examples', 'read-only', 'write-only', 'deprecated', 'meta', 'values',
        'discriminator', 'secret', 'key', 'visibility', 'messages',
    ];

    /** The stages a field may be seen in (TypeSpec's Lifecycle; G5 of dextrin's design/typespec.md). */
    private const VISIBILITIES = ['create', 'read', 'update', 'delete', 'query'];

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
        // `read-only` says `visibility: [:read]`; the two together would
        // say it twice, perhaps differently.
        if (array_key_exists('visibility', $named) && array_key_exists('read-only', $named)) {
            throw new SchemaCompileError("a doc's read-only and visibility say the same thing; give visibility alone");
        }
        $named = self::plainNames($named);
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
            'secret' => is_bool($value) ? null : 'true or false',
            'key' => is_bool($value) || is_string($value) ? null : 'true, false or a name',
            'visibility' => self::visibility($value),
            'messages' => self::messages($value),
            'discriminator' => self::discriminator($value, $type),
            'values' => $type !== null && $type->form === 'enum' && self::isMap($value)
                ? self::valueAnnotations($value, $type->literals ?? [])
                : "a map of an enum's values to their annotations, on an enum",
            default => throw new SchemaCompileError("{$key} is no annotation; a doc holds " . implode(', ', self::ANNOTATION_KEYS)),
        };
    }

    /**
     * What `visibility` takes when `$value` is not a list, `null` when it
     * is; a list naming no stage throws.
     */
    private static function visibility(mixed $value): ?string
    {
        if (!$value instanceof DXNList) {
            return 'a list of ' . implode(', ', self::VISIBILITIES);
        }
        foreach ($value->items as $item) {
            if (!in_array(self::literalName($item), self::VISIBILITIES, true)) {
                throw new SchemaCompileError(
                    "a doc's visibility names " . self::inspect($item) . ', which is none of ' . implode(', ', self::VISIBILITIES),
                );
            }
        }
        return null;
    }

    /**
     * What `messages` takes when `$value` is not a map, `null` when it
     * is; a key that is no refine key, or a message that is no string,
     * throws.
     */
    private static function messages(mixed $value): ?string
    {
        if (!self::isMap($value)) {
            return 'a map of refine keys to messages';
        }
        $pairs = self::pairsOf($value);
        usort($pairs, static fn (array $a, array $b): int => Equality::dxnCompare($a[0], $b[0]));
        foreach ($pairs as [$key, $message]) {
            if (!in_array(self::literalName($key), self::REFINE_KEYS, true)) {
                throw new SchemaCompileError("a doc's messages are for refine keys; " . self::inspect($key) . ' is none');
            }
            if (!is_string($message)) {
                throw new SchemaCompileError("a doc's messages are strings, not " . self::inspect($message));
            }
        }
        return null;
    }

    /**
     * What `discriminator` takes when `$value` is no name, `null` when it
     * is; one that does not stand on an any-of or a one-of throws.
     */
    private static function discriminator(mixed $value, ?TypeExpr $type): ?string
    {
        if (!($value instanceof DXNKeyword || $value instanceof DXNSymbol || is_string($value))) {
            return "a field's name";
        }
        if (!self::hasVariants($type)) {
            throw new SchemaCompileError("a doc's discriminator stands on an any-of or a one-of, not " . self::inspectType($type));
        }
        return null;
    }

    private static function hasVariants(?TypeExpr $type): bool
    {
        return match ($type?->form) {
            'doc' => self::hasVariants($type->elem),
            'any-of', 'one-of' => true,
            default => false,
        };
    }

    /**
     * The annotations whose values are names, as strings -- what a reader
     * compares, whatever spelling the document gave: `discriminator`, each
     * stage of `visibility`, each key of `messages`.
     *
     * @param array<string, mixed> $named
     * @return array<string, mixed>
     */
    private static function plainNames(array $named): array
    {
        if (array_key_exists('discriminator', $named)) {
            $named['discriminator'] = self::literalName($named['discriminator']);
        }
        if (($named['visibility'] ?? null) instanceof DXNList) {
            $named['visibility'] = new DXNList(array_map(self::literalName(...), $named['visibility']->items));
        }
        if (array_key_exists('messages', $named) && self::isMap($named['messages'])) {
            $messages = [];
            foreach (self::pairsOf($named['messages']) as [$key, $message]) {
                $messages[(string) self::literalName($key)] = $message;
            }
            $named['messages'] = $messages;
        }
        return $named;
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
