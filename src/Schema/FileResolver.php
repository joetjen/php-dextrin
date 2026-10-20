<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Text\Parser;

/**
 * A resolver ({@see Registry::putResolver()}) finding a struct's schema
 * in `.dxns` files on disk, by the convention DXN.md §4.6 states for
 * every implementation -- so one set of schema files serves them all.
 * Mirrors `Dextrin.Schema.FileResolver`. Swappable like any resolver:
 * anyone with another convention writes their own.
 *
 * **The convention.** A name is segments joined by `.`, with at most one
 * `/` between a namespace and a name (`Foo.Bar/Baz`); every segment is a
 * directory but the last, which names the file. A schema of version `N`
 * named `Foo.Bar/Baz` is looked for, on each search path in order, in
 * `Foo/Bar/Baz-vN.dxns` (version 1 also `Foo/Bar/Baz.dxns`),
 * `Foo/Bar/Baz/vN.dxns`, `Foo.Bar.Baz-vN.dxns` (version 1 also
 * `Foo.Bar.Baz.dxns`), and the entry `Baz` in `Foo.Bar.dxns`, by its
 * `%schema(N)`; a name with no `/` is its own namespace: `Money` in
 * `Money.dxns`.
 *
 * - **The first search path that has the name at all decides.**
 * - **A file's version is its schema's**, or the file is refused.
 * - **One version is found once**: two files holding the same version
 *   are refused, naming both.
 * - **Every version found is loaded**, so an older struct reaches the
 *   materializer.
 *
 * A refusal is a thrown {@see SchemaCompileError} (`schema/compile`).
 */
final class FileResolver
{
    private function __construct()
    {
    }

    /**
     * A resolver searching `$searchPaths` in order.
     *
     * @param list<string> $searchPaths
     * @return \Closure(string): (list<Compiled>|null)
     */
    public static function forPaths(array $searchPaths): \Closure
    {
        return static function (string $name) use ($searchPaths): ?array {
            foreach ($searchPaths as $dir) {
                $found = self::inPath($dir, $name);
                if ($found !== null) {
                    return $found;
                }
            }
            return null;
        };
    }

    /**
     * Every version of `$name` `$dir` holds, each under the name asked
     * for, or null when it holds none, so the next search path is asked.
     *
     * @return list<Compiled>|null
     */
    private static function inPath(string $dir, string $name): ?array
    {
        [$entry, $candidates] = self::candidates($dir, $name);
        $found = [];
        foreach ($candidates as [$file, $expected]) {
            foreach (self::load($file, $entry, $expected, $dir, $name) as $one) {
                $found[] = $one;
            }
        }
        if ($found === []) {
            return null;
        }
        $holders = [];
        foreach ($found as [$version, , $file]) {
            $holders[$version][] = $file;
        }
        foreach ($holders as $version => $files) {
            if (count($files) > 1) {
                sort($files);
                throw new SchemaCompileError(
                    "{$name} version {$version} is in two files, " . implode(' and ', $files) . '; it is found once (DXN.md §4.6)'
                );
            }
        }
        return array_map(
            static fn (array $one): Compiled => $one[1]->withName($name),
            $found,
        );
    }

    /**
     * The entry a file holds the schema under -- the name after its `/`,
     * or the whole name when it has none -- and every file that may hold
     * it, with the version its name says (`null` for the namespace file,
     * which holds whichever its `%schema(N)` says).
     *
     * @return array{0: string, 1: list<array{0: string, 1: int|null}>}
     */
    private static function candidates(string $dir, string $name): array
    {
        $slash = strpos($name, '/');
        $namespace = $slash === false ? null : substr($name, 0, $slash);
        $entry = $slash === false ? $name : substr($name, $slash + 1);
        $segments = preg_split('#[/.]#', $name) ?: [$name];
        $stem = $segments[count($segments) - 1];
        $nested = implode('/', [$dir, ...array_slice($segments, 0, -1)]);
        $flat = $dir . '/' . implode('.', $segments);

        $all = [
            // One file, one candidate: a name without a namespace is both
            // its own version-1 file and its namespace file, and is read as
            // the latter.
            [$dir . '/' . ($namespace ?? $entry) . '.dxns', null],
            [$nested . '/' . $stem . '.dxns', 1],
            [$flat . '.dxns', 1],
            ...self::numbered($nested, $stem . '-v'),
            ...self::numbered($nested . '/' . $stem, 'v'),
            ...self::numbered(dirname($flat), basename($flat) . '-v'),
        ];
        $seen = [];
        $candidates = [];
        foreach ($all as [$file, $expected]) {
            $key = self::normalized($file);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $candidates[] = [$file, $expected];
            }
        }
        return [$entry, $candidates];
    }

    /**
     * The files in `$dir` named `<prefix>N.dxns`, each with its version.
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function numbered(string $dir, string $prefix): array
    {
        $names = is_dir($dir) ? scandir($dir) : false;
        if ($names === false) {
            return [];
        }
        $found = [];
        foreach ($names as $file) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)\.dxns$/', $file, $m) === 1 && (int) $m[1] > 0) {
                $found[] = [$dir . '/' . $file, (int) $m[1]];
            }
        }
        return $found;
    }

    /** `$file` with its `./` and `//` taken out, so one file is one candidate. */
    private static function normalized(string $file): string
    {
        return preg_replace(['#/\./#', '#/{2,}#'], ['/', '/'], $file) ?? $file;
    }

    /**
     * The versions of `$entry` in `$file`: none when the file is not there
     * or holds no such entry. A file that does not compile, or holds
     * another version than its name says, is refused.
     *
     * The file is compiled as a tree compiles it ({@see Tree}): its entries
     * under their full names (DXN.md §4.6), over the named types the rest
     * of its search path `$dir` defines -- so a field naming another
     * file's shape or named type resolves.
     *
     * @return list<array{0: int, 1: Compiled, 2: string}>
     */
    private static function load(string $file, string $entry, ?int $expected, string $dir, string $name): array
    {
        if (!is_file($file)) {
            return [];
        }
        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }
        try {
            $doc = Parser::parse($source);
            $base = new Registry();
            foreach (Tree::namedTypes([$dir]) as $typeName => $type) {
                $base = $base->putTypeAlias($typeName, $type);
            }
            $relative = ltrim(substr(self::normalized($file), strlen(rtrim(self::normalized($dir), '/'))), '/');
            $keys = is_array($doc) ? array_map(static fn (int|string $k): string => self::keyName(MapBuilder::toDxnMapKey($k)), array_keys($doc)) : [];
            $registry = Compiler::compile($doc, $base, Layout::of($relative, $keys)->names);
        } catch (\Throwable $err) {
            throw new SchemaCompileError("{$file}: {$err->getMessage()}");
        }
        $versions = $registry->structSchemas()[$name] ?? $registry->structSchemas()[$entry] ?? [];
        if ($versions === []) {
            return [];
        }
        if ($expected !== null && array_keys($versions) !== [$expected]) {
            throw new SchemaCompileError(
                "{$file} holds {$entry} as version " . implode(', ', array_keys($versions))
                . ", but its name says version {$expected} (DXN.md §4.6)"
            );
        }
        $found = [];
        foreach ($versions as $version => $compiled) {
            $found[] = [$version, $compiled, $file];
        }
        return $found;
    }

    private static function keyName(mixed $key): string
    {
        return match (true) {
            $key instanceof DXNKeyword => $key->name,
            default => is_scalar($key) ? (string) $key : '',
        };
    }
}
