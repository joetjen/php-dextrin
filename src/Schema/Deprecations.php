<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNMap;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\Equality;

/**
 * Warnings for what is deprecated (G10 of dextrin's design/typespec.md;
 * DXNS.md §1b): "mark Schemas in Dextrin as deprecated, and generate a
 * warning when they are used", one version of a schema as well as the
 * whole schema. Mirrors `Dextrin.Schema.Deprecations`.
 *
 * `deprecated: true | "why"` stands on a version of a schema, a named
 * type, a field's type, a union variant, or an enum value (`values:`).
 * It warns in two places:
 *
 * - **when a schema compiles** against something deprecated -- a field
 *   naming a deprecated schema or named type, a base, or a base pinned to
 *   a deprecated version (`includes Pet(1)`). The registry keeps those
 *   warnings ({@see Registry::warnings()}). Something deprecated naming
 *   something deprecated stays silent.
 * - **when data goes through it**, decoding or encoding: a struct written
 *   with a deprecated version, or of a schema whose latest version is
 *   deprecated, and a value accepted through a deprecated type, variant
 *   or enum value.
 *
 * The registry's `deprecations` says how often data warns -- `'once'` per
 * registry and deprecated thing (the default), `'every'` time, `'off'`,
 * or `'error'`, which refuses the data (`schema/deprecated`). Its
 * `onDeprecated` callable receives each warning in place of
 * `trigger_error($text, E_USER_DEPRECATED)`.
 *
 * "Once" outlives a single decode: the registry holds a
 * {@see DeprecationsSeen}, one key per deprecated thing, given out as its
 * schema or named type is put in the registry. Every copy of the registry
 * shares it; one that is given new keys gets its own, carrying what the
 * old one had seen.
 *
 * @phpstan-type Warning array{name: ?string, version: ?int, message: ?string, category: string, at: ?string}
 */
final class Deprecations
{
    /** The category of every deprecation warning, and of the error `'error'` makes of one. */
    public const CATEGORY = 'schema/deprecated';

    /** How often data going through something deprecated warns. */
    public const MODES = ['once', 'every', 'off', 'error'];

    /**
     * Each node's key, kept so a node is serialised once.
     *
     * @var \WeakMap<TypeExpr, string>|null
     */
    private static ?\WeakMap $nodeKeys = null;

    private function __construct()
    {
    }

    /**
     * Whether `$notes`, an annotation map, deprecates what it stands on:
     * `[true, why]`, `why` the reason or `null`, or `null` when it does not.
     *
     * @param array<mixed> $notes the annotations
     * @return array{0: true, 1: ?string}|null
     */
    public static function reason(array $notes): ?array
    {
        $why = $notes['deprecated'] ?? null;
        return match (true) {
            is_string($why) => [true, $why],
            $why === true => [true, null],
            default => null,
        };
    }

    /**
     * A warning about `$name` at `$version`, for `$why`, used by `$at`.
     *
     * @param string|null $name what is deprecated, when it has a name
     * @param int|null $version its version, when it has one
     * @param string|null $why the reason, `null` for `deprecated: true`
     * @param string|null $at the schema or type that uses it, at compile time
     * @return Warning
     */
    public static function warning(?string $name, ?int $version, ?string $why, ?string $at = null): array
    {
        return ['name' => $name, 'version' => $version, 'message' => $why, 'category' => self::CATEGORY, 'at' => $at];
    }

    /**
     * A warning in words: `Pet(1) is deprecated: use Pet(2)`, or, at
     * compile time, `Show uses Pet(1), which is deprecated: use Pet(2)`.
     *
     * @param Warning $warning the warning
     * @return string its text
     */
    public static function text(array $warning): string
    {
        ['name' => $name, 'version' => $version, 'message' => $why, 'at' => $at] = $warning;
        $what = match (true) {
            $name === null => 'a deprecated type or value',
            $version === null => $name,
            default => "{$name}({$version})",
        };
        $used = $at !== null ? "{$at} uses {$what}, which is deprecated" : "{$what} is deprecated";
        return $why !== null ? "{$used}: {$why}" : $used;
    }

    // ---- data going through it ----------------------------------------------------

    /**
     * A struct of `$name` written with `$version` (`null`: the latest) has
     * been read or checked: a warning for that version when it is
     * deprecated, and for the latest when that is -- the whole schema.
     * Under `'error'` the first is thrown instead.
     *
     * @internal
     * @param Registry $registry the registry it was read or checked with
     * @param string $name the struct's name
     * @param int|null $version the version it was written with
     * @throws DXNError `schema/deprecated`, under `'error'`
     */
    public static function structUsed(Registry $registry, string $name, ?int $version): void
    {
        if ($registry->deprecations() === 'off') {
            return;
        }
        try {
            $latest = $registry->fetchStructSchema($name)?->compiled->version;
        } catch (\Throwable) {
            return;
        }
        if ($latest === null) {
            return;
        }
        foreach (array_unique([$version ?? $latest, $latest]) as $at) {
            $notes = $registry->fetchStructSchema($name, $at)?->compiled->annotations;
            $reason = $notes === null ? null : self::reason($notes);
            if ($reason !== null) {
                $error = self::used($registry, "schema:{$name}:{$at}", self::warning($name, $at, $reason[1]));
                if ($error !== null) {
                    throw $error;
                }
            }
        }
    }

    /**
     * A value, `$conformed`, went through `$node`, a `doc` that may
     * deprecate what it stands on or one of its enum values: reported as
     * the registry says. `false` when `'error'` refuses it.
     *
     * @internal
     * @param Registry|null $registry the check's registry; without one nothing is reported
     * @param TypeExpr $node the `doc` node
     * @param mixed $conformed the value it accepted
     * @return bool whether the value may pass
     */
    public static function nodeUsed(?Registry $registry, TypeExpr $node, mixed $conformed): bool
    {
        if ($registry === null || $registry->deprecations() === 'off') {
            return true;
        }
        $notes = $node->notes ?? [];
        $reason = self::reason($notes);
        if ($reason !== null) {
            $title = $notes['title'] ?? null;
            if (self::used($registry, 'node:' . self::nodeKey($node), self::warning(is_string($title) ? $title : null, null, $reason[1])) !== null) {
                return false;
            }
        }
        $values = $notes['values'] ?? null;
        if (!is_array($values) && !$values instanceof DXNMap) {
            return true;
        }
        foreach (self::pairs($values) as [$literal, $valueNotes]) {
            if (!Equality::dxnEquals(self::plain($literal), self::plain($conformed))) {
                continue;
            }
            $reason = is_array($valueNotes) ? self::reason($valueNotes) : null;
            if ($reason === null) {
                return true;
            }
            $key = 'value:' . self::nodeKey($node) . ':' . Equality::canonicalKey($literal);
            return self::used($registry, $key, self::warning(null, null, $reason[1])) === null;
        }
        return true;
    }

    /**
     * An enum value's annotation is keyed by the literal as written; a
     * keyword is compared by its name.
     */
    private static function plain(mixed $value): mixed
    {
        return $value instanceof DXNKeyword ? new DXNKeyword($value->name) : $value;
    }

    /**
     * One use of the deprecated thing `$key`, reported as the registry
     * says: the error `'error'` makes of it, or `null`.
     *
     * @param Warning $warning
     */
    private static function used(Registry $registry, string $key, array $warning): ?DXNError
    {
        $mode = $registry->deprecations();
        if ($mode === 'error') {
            return new DXNError(self::text($warning), stage: 'schema', category: self::CATEGORY);
        }
        if ($mode === 'every' || $registry->firstDeprecatedUse($key)) {
            self::report($registry, $warning);
        }
        return null;
    }

    /** @param Warning $warning */
    private static function report(Registry $registry, array $warning): void
    {
        $hear = $registry->onDeprecated();
        if ($hear !== null) {
            $hear($warning);
            return;
        }
        trigger_error(self::text($warning), E_USER_DEPRECATED);
    }

    // ---- compiling ------------------------------------------------------------------

    /**
     * The keys of every deprecated thing `$item` holds -- a schema version
     * (a {@see Compiled} named `$name`) or a named type's definition:
     * what the registry keeps "once" for, given out as each is put in it,
     * so one loaded later by a resolver has its keys too.
     *
     * @internal
     * @param Compiled|TypeExpr $item what is put in the registry
     * @param string $name the name it is put under
     * @return list<string>
     */
    public static function keys(Compiled|TypeExpr $item, string $name): array
    {
        if ($item instanceof TypeExpr) {
            return array_values(array_unique(self::nodeKeys($item)));
        }
        $own = self::reason($item->annotations) === null ? [] : ["schema:{$name}:{$item->version}"];
        $fields = array_merge([], ...array_map(static fn (Field $f): array => self::nodeKeys($f->type), $item->fields));
        return array_values(array_unique([...$own, ...$fields]));
    }

    /**
     * Every `doc` in `$type` that deprecates, and every deprecated enum
     * value it annotates, however deep.
     *
     * @return list<string>
     */
    private static function nodeKeys(TypeExpr $type): array
    {
        $own = [];
        if ($type->form === 'doc') {
            $notes = $type->notes ?? [];
            if (self::reason($notes) !== null) {
                $own[] = 'node:' . self::nodeKey($type);
            }
            $values = $notes['values'] ?? null;
            if (is_array($values) || $values instanceof DXNMap) {
                foreach (self::pairs($values) as [$literal, $valueNotes]) {
                    if (is_array($valueNotes) && self::reason($valueNotes) !== null) {
                        $own[] = 'value:' . self::nodeKey($type) . ':' . Equality::canonicalKey($literal);
                    }
                }
            }
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
            ...(is_array($type->extras) ? $type->extras : []),
            ...TypeExpr::heldTypes($type->constraints ?? []),
            ...$bounds,
        ];
        return array_merge($own, ...array_map(self::nodeKeys(...), $children));
    }

    /**
     * A node's key: the same for two nodes that are equal, as the
     * reference's hash of the node is.
     */
    private static function nodeKey(TypeExpr $node): string
    {
        self::$nodeKeys ??= new \WeakMap();
        if (!isset(self::$nodeKeys[$node])) {
            try {
                self::$nodeKeys[$node] = md5(serialize($node));
            } catch (\Throwable) {
                self::$nodeKeys[$node] = 'object:' . spl_object_id($node);
            }
        }
        return self::$nodeKeys[$node];
    }

    /**
     * The warnings compiling `$entries` -- a document's `[name, raw]`
     * entries as written, by the names they were loaded under, now in
     * `$registry` -- gives: each entry that is not itself deprecated and
     * names something that is, by a type reference anywhere in its body
     * or a base in its head. A reference never pins a version, so it
     * warns when the latest is deprecated; a base pinned to a version
     * (`includes Pet(1)`) warns when that one is. `$names` maps a name as
     * written to the name it was loaded under.
     *
     * @internal
     * @param list<array{0: string, 1: mixed}> $entries the document's entries
     * @param array<string, string> $names written name => loaded name
     * @param Registry $registry the registry they were compiled into
     * @return list<Warning>
     */
    public static function compileWarnings(array $entries, array $names, Registry $registry): array
    {
        usort($entries, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $warnings = [];
        foreach ($entries as [$at, $raw]) {
            if (self::deprecatedEntry($raw)) {
                continue;
            }
            $seen = [];
            foreach (self::usedNames($raw) as [$name, $version]) {
                $full = $names[$name] ?? $name;
                $id = $full . '(' . ($version ?? '') . ')';
                if ($full === $at || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $target = self::deprecatedTarget($registry, $full, $version);
                if ($target !== null) {
                    $warnings[] = self::warning($full, $target[1], $target[0], $at);
                }
            }
        }
        return $warnings;
    }

    /** Whether `$raw`, an entry as written, is a `{:doc ... %{deprecated: ...}}` itself. */
    private static function deprecatedEntry(mixed $raw): bool
    {
        if (
            !$raw instanceof DXNTuple || count($raw->items) !== 3
            || !$raw->items[0] instanceof DXNKeyword || $raw->items[0]->name !== 'doc'
            || (!is_array($raw->items[2]) && !$raw->items[2] instanceof DXNMap)
        ) {
            return false;
        }
        foreach (self::pairs($raw->items[2]) as [$key, $value]) {
            $name = $key instanceof DXNKeyword ? $key->name : $key;
            if ($name === 'deprecated' && $value !== false && $value !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * The names `$raw` uses: every symbol in it, at no version, each
     * pinned name (`Pet(1)`) at its version, and each base of a `%schema`
     * or `%shape` head at the version it names.
     *
     * @return list<array{0: string, 1: ?int}>
     */
    private static function usedNames(mixed $raw): array
    {
        return match (true) {
            $raw instanceof DXNSymbol => [[$raw->name, null]],
            $raw instanceof DXNStruct => [
                ...array_map(static fn (array $base): array => [$base[1], $base[2]], $raw->bases),
                ...self::usedNamesAll($raw->isKeyed() ? array_merge([], ...$raw->pairs()) : $raw->values()),
                ...self::usedNamesAll($raw->rest ?? []),
            ],
            // `Pet(1)`: the name at the version it pins (DXNS.md §5c).
            $raw instanceof DXNTuple && DXNTuple::pinned($raw) !== null => [DXNTuple::pinned($raw)],
            $raw instanceof DXNTuple, $raw instanceof DXNList => self::usedNamesAll($raw->items),
            $raw instanceof DXNCustomTag => self::usedNames($raw->value),
            $raw instanceof DXNMap => self::usedNamesAll(array_merge([], ...$raw->pairs())),
            is_array($raw) => self::usedNamesAll([...array_map(MapBuilder::toDxnMapKey(...), array_keys($raw)), ...array_values($raw)]),
            default => [],
        };
    }

    /**
     * @param array<mixed> $values
     * @return list<array{0: string, 1: ?int}>
     */
    private static function usedNamesAll(array $values): array
    {
        return array_merge([], ...array_map(self::usedNames(...), array_values($values)));
    }

    /**
     * Whether `$name` at `$version` (`null`: the latest) is deprecated: a
     * schema's version, or a named type. `[why, version]`, or `null`.
     *
     * @return array{0: ?string, 1: ?int}|null
     */
    private static function deprecatedTarget(Registry $registry, string $name, ?int $version): ?array
    {
        $versions = $registry->structSchemas()[$name] ?? [];
        if ($versions !== []) {
            $at = $version ?? max(array_keys($versions));
            $compiled = $versions[$at] ?? null;
            $reason = $compiled === null ? null : self::reason($compiled->annotations);
            return $reason === null ? null : [$reason[1], $at];
        }
        // A named type, at `$version` when one is pinned (Way B, DXNS.md
        // §5c): the version in the warning only where the type has more
        // than one.
        $typeVersions = $registry->typeVersions()[$name] ?? [];
        $type = $version !== null ? ($typeVersions[$version] ?? null) : $registry->fetchTypeAlias($name);
        $shown = count($typeVersions) > 1 ? ($version ?? max(array_keys($typeVersions))) : null;
        if ($type !== null && $type->form === 'generic') {
            $type = $type->base;
        }
        if ($type === null || $type->form !== 'doc') {
            return null;
        }
        $reason = self::reason($type->notes ?? []);
        return $reason === null ? null : [$reason[1], $shown];
    }

    /**
     * A map's pairs, each key as DXN has it.
     *
     * @param array<int|string, mixed>|DXNMap $map
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function pairs(array|DXNMap $map): array
    {
        return $map instanceof DXNMap
            ? $map->pairs()
            : array_map(static fn (int|string $k, mixed $v): array => [MapBuilder::toDxnMapKey($k), $v], array_keys($map), array_values($map));
    }
}
