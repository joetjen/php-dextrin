<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Text\Parser;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNMap;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTuple;

/**
 * A tree of `.dxns` files read as one (Jan, 2026-10-20), the port of
 * `Dextrin.Schema.Tree`: every entry named from where its file lies
 * (DXN.md §4.6, {@see Layout}), the files compiled in the order their
 * references need -- so a reference to another file's named type
 * resolves whatever order the files sort in -- and every problem reported
 * with its file and line:
 *
 * - a file that is not DXN, or not a map of names to definitions;
 * - a definition that does not compile;
 * - a versioned file holding another version, and one version of a name
 *   held by two files;
 * - files that need each other (a cycle across files is not resolved yet);
 * - **a name that resolves to nothing** -- a typo such as
 *   `acme.comon/Money` -- unless its namespace is declared extern.
 *
 * `dextrin check` is this, for CI.
 *
 * @phpstan-type Problem array{file: string, line: int, message: string}
 * @phpstan-type TreeFile array{file: string, source: string, doc: list<array{0: mixed, 1: mixed}>, keys: list<string>, layout: Layout}
 */
final class Tree
{
    private function __construct()
    {
    }

    /**
     * Every `.dxns` file under `$roots` (files or directories, searched
     * recursively), compiled into one registry: `['ok' => true, 'registry'
     * => ..., 'summary' => ['files' => n, 'definitions' => n]]`, or
     * `['ok' => false, 'problems' => [...]]` with every problem found, in
     * file and line order. `$extern` lists namespaces whose names are
     * known to be supplied elsewhere.
     *
     * @param list<string> $roots
     * @param list<string> $extern
     * @return array{ok: true, registry: Registry, summary: array{files: int, definitions: int}}|array{ok: false, problems: list<Problem>}
     */
    public static function load(array $roots, array $extern = [], ?Registry $base = null): array
    {
        $problems = [];
        $files = self::read($roots, $problems);
        foreach ($files as $file) {
            $p = self::versionProblem($file);
            if ($p !== null) {
                $problems[] = $p;
            }
        }
        $index = self::index($files);
        array_push($problems, ...self::twice($index));
        $ordered = self::order($files, $index, $problems);
        $registry = self::compiled($ordered, $base ?? new Registry(), $problems);
        array_push($problems, ...self::unknown($ordered, $registry, $index, $extern));

        if ($problems === []) {
            $definitions = array_sum(array_map(static fn (array $f): int => count($f['keys']), $files));
            return ['ok' => true, 'registry' => $registry, 'summary' => ['files' => count($files), 'definitions' => $definitions]];
        }
        usort($problems, static fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
        return ['ok' => false, 'problems' => $problems];
    }

    /**
     * The named types `$roots` define, compiled as {@see self::load()}
     * compiles them but past any problem -- what a file of the tree needs
     * from the others when it is compiled on its own ({@see FileResolver}).
     *
     * @param list<string> $roots
     * @return array<string, TypeExpr>
     */
    public static function namedTypes(array $roots): array
    {
        $ignored = [];
        $files = self::read($roots, $ignored);
        $ordered = self::order($files, self::index($files), $ignored);
        return self::compiled($ordered, new Registry(), $ignored)->typeAliases();
    }

    // ---- reading -------------------------------------------------------------------

    /**
     * @param list<string> $roots
     * @param list<Problem> $problems
     * @return list<TreeFile>
     */
    private static function read(array $roots, array &$problems): array
    {
        $files = [];
        foreach ($roots as $root) {
            foreach (self::filesUnder($root) as [$dir, $relative]) {
                $source = (string) file_get_contents("{$dir}/{$relative}");
                try {
                    $doc = Parser::parse($source);
                } catch (\Throwable $err) {
                    $offset = $err instanceof DXNError ? $err->offset : null;
                    $problems[] = self::problem($relative, self::lineAt($source, $offset), $err->getMessage());
                    continue;
                }
                $pairs = self::pairsOf($doc);
                if ($pairs === null) {
                    $problems[] = self::problem($relative, 1, 'a .dxns document is a map of names to definitions');
                    continue;
                }
                $keys = array_map(static fn (array $p): string => self::keyName($p[0]), $pairs);
                $files[] = ['file' => $relative, 'source' => $source, 'doc' => $pairs, 'keys' => $keys, 'layout' => Layout::of($relative, $keys)];
            }
        }
        return $files;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function filesUnder(string $root): array
    {
        if (!is_dir($root)) {
            return [[dirname($root), basename($root)]];
        }
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.dxns')) {
                $found[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(rtrim($root, '/')) + 1));
            }
        }
        sort($found);
        return array_map(static fn (string $rel): array => [rtrim($root, '/'), $rel], $found);
    }

    /** @return list<array{0: mixed, 1: mixed}>|null */
    private static function pairsOf(mixed $doc): ?array
    {
        if ($doc instanceof DXNMap) {
            return $doc->pairs();
        }
        if (!is_array($doc) || ($doc !== [] && array_is_list($doc))) {
            return null;
        }
        $pairs = [];
        foreach ($doc as $k => $v) {
            $pairs[] = [MapBuilder::toDxnMapKey($k), $v];
        }
        return $pairs;
    }

    private static function keyName(mixed $key): string
    {
        return match (true) {
            $key instanceof DXNKeyword, $key instanceof DXNSymbol => $key->name,
            is_string($key) => $key,
            is_int($key) => (string) $key,
            default => get_debug_type($key),
        };
    }

    /** @param TreeFile $file */
    private static function held(array $file, string $key): mixed
    {
        foreach ($file['doc'] as [$k, $v]) {
            if (self::keyName($k) === $key) {
                return $v;
            }
        }
        return null;
    }

    // ---- layout ------------------------------------------------------------------

    /**
     * A versioned file holds its entry as the version its name says.
     *
     * @param TreeFile $file
     * @return Problem|null
     */
    private static function versionProblem(array $file): ?array
    {
        $holder = $file['layout']->holder;
        $version = $file['layout']->version;
        if ($holder === null || $version === null) {
            return null;
        }
        $value = self::held($file, $holder);
        if ($value instanceof DXNStruct && $value->name === 'schema') {
            $held = $value->version ?? 1;
            return $held === $version
                ? null
                : self::problem($file['file'], self::entryLine($file, $holder), "holds {$holder} as version {$held}, but its name says version {$version} (DXN.md §4.6)");
        }
        return self::problem($file['file'], self::entryLine($file, $holder), "a versioned file holds a %schema(N), not {$holder}'s kind");
    }

    // ---- names across files ------------------------------------------------------

    private static function versionOf(mixed $value): int|string
    {
        return $value instanceof DXNStruct && $value->name === 'schema' ? ($value->version ?? 1) : 'type';
    }

    /**
     * Every full name the tree defines, with the files and versions defining it.
     *
     * @param list<TreeFile> $files
     * @return array<string, list<array{0: string, 1: int|string}>>
     */
    private static function index(array $files): array
    {
        $index = [];
        foreach ($files as $file) {
            foreach ($file['layout']->names as $key => $name) {
                $index[$name][] = [$file['file'], self::versionOf(self::held($file, (string) $key))];
            }
        }
        return $index;
    }

    /**
     * One version of a name in two files (§4.6): found once, or refused
     * naming both.
     *
     * @param array<string, list<array{0: string, 1: int|string}>> $index
     * @return list<Problem>
     */
    private static function twice(array $index): array
    {
        $found = [];
        foreach ($index as $name => $holders) {
            $byVersion = [];
            foreach ($holders as [$file, $version]) {
                $byVersion[(string) $version][] = $file;
            }
            foreach ($byVersion as $version => $files) {
                if (count($files) < 2) {
                    continue;
                }
                sort($files);
                $what = $version === 'type' ? $name : "{$name} version {$version}";
                $found[] = self::problem($files[0], 1, "{$what} is in two files, " . implode(' and ', $files) . '; it is found once (DXN.md §4.6)');
            }
        }
        return $found;
    }

    /**
     * The files in an order that compiles each after every file it refers
     * to; files that need each other are a problem, and are left out.
     *
     * @param list<TreeFile> $files
     * @param array<string, list<array{0: string, 1: int|string}>> $index
     * @param list<Problem> $problems
     * @return list<TreeFile>
     */
    private static function order(array $files, array $index, array &$problems): array
    {
        $needs = [];
        $byFile = [];
        foreach ($files as $file) {
            $needs[$file['file']] = self::needsOf($file, $index);
            $byFile[$file['file']] = $file;
        }
        $done = [];
        $pending = array_keys($byFile);
        while ($pending !== []) {
            $ready = array_values(array_filter($pending, static fn (string $f): bool => array_diff($needs[$f], $done) === []));
            if ($ready === []) {
                sort($pending);
                $problems[] = self::problem($pending[0], 1, 'these files need each other, which is not resolved yet: ' . implode(', ', $pending));
                break;
            }
            array_push($done, ...$ready);
            $pending = array_values(array_diff($pending, $ready));
        }
        return array_map(static fn (string $f): array => $byFile[$f], $done);
    }

    /**
     * The other files `$file` refers to.
     *
     * @param TreeFile $file
     * @param array<string, list<array{0: string, 1: int|string}>> $index
     * @return list<string>
     */
    private static function needsOf(array $file, array $index): array
    {
        $out = [];
        foreach ($file['doc'] as [, $value]) {
            foreach (self::symbols($value) as $written) {
                $name = $file['layout']->names[$written] ?? $written;
                foreach ($index[$name] ?? [] as [$holder]) {
                    if ($holder !== $file['file']) {
                        $out[$holder] = true;
                    }
                }
            }
        }
        return array_map('strval', array_keys($out));
    }

    /** @return list<string> */
    private static function symbols(mixed $value): array
    {
        $all = static fn (array $items): array => array_merge([], ...array_map(self::symbols(...), array_values($items)));
        return match (true) {
            $value instanceof DXNSymbol => [$value->name],
            $value instanceof DXNTuple, $value instanceof DXNList => $all($value->items),
            $value instanceof DXNStruct => $all(array_map(static fn (array $p): mixed => $p[1], $value->pairs())),
            $value instanceof DXNMap => $all(array_map(static fn (array $p): mixed => $p[1], $value->pairs())),
            is_array($value) => $all($value),
            default => [],
        };
    }

    // ---- compiling -----------------------------------------------------------------

    /**
     * @param list<TreeFile> $files
     * @param list<Problem> $problems
     */
    private static function compiled(array $files, Registry $registry, array &$problems): Registry
    {
        foreach ($files as $file) {
            $doc = [];
            foreach ($file['doc'] as [$k, $v]) {
                $doc[self::keyName($k)] = $v;
            }
            try {
                $registry = Compiler::compile($doc, $registry, $file['layout']->names);
            } catch (\Throwable $err) {
                $category = $err instanceof HasCategory ? $err->category() : ($err instanceof DXNError ? $err->category : null);
                // Reported up front, naming both files (`twice`).
                if ($category === 'schema/duplicate-version') {
                    continue;
                }
                $problems[] = self::problem($file['file'], self::errorLine($file, $err->getMessage()), $err->getMessage());
            }
        }
        return $registry;
    }

    /**
     * The line of the entry a compile error names.
     *
     * @param TreeFile $file
     */
    private static function errorLine(array $file, string $message): int
    {
        if (preg_match('/^(?:schema|named type) "([^"]+)"/', $message, $m) !== 1) {
            return 1;
        }
        foreach ($file['layout']->names as $key => $full) {
            if ($full === $m[1]) {
                return self::entryLine($file, (string) $key);
            }
        }
        return 1;
    }

    // ---- names that resolve to nothing --------------------------------------------

    /**
     * @param list<TreeFile> $files
     * @param array<string, mixed> $index
     * @param list<string> $extern
     * @return list<Problem>
     */
    private static function unknown(array $files, Registry $registry, array $index, array $extern): array
    {
        $found = [];
        $structs = $registry->structSchemas();
        foreach ($files as $file) {
            foreach ($file['layout']->names as $name) {
                foreach (array_unique(self::referencesOf($registry, $name)) as $reference) {
                    if (isset($structs[$reference]) || isset($index[$reference]) || self::isExtern($reference, $extern)) {
                        continue;
                    }
                    $p = self::problem($file['file'], self::mentionLine($file, $reference), "{$reference} names nothing in this tree (declare its namespace extern if it is defined elsewhere)");
                    $found["{$p['file']}:{$p['line']}:{$p['message']}"] = $p;
                }
            }
        }
        return array_values($found);
    }

    /** @return list<string> */
    private static function referencesOf(Registry $registry, string $name): array
    {
        $alias = $registry->fetchTypeAlias($name);
        if ($alias !== null) {
            return self::typeReferences($alias);
        }
        $out = [];
        foreach ($registry->structSchemas()[$name] ?? [] as $compiled) {
            foreach ($compiled->fields as $field) {
                array_push($out, ...self::typeReferences($field->type));
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function typeReferences(?TypeExpr $type): array
    {
        if ($type === null) {
            return [];
        }
        $all = static fn (?array $ts): array => array_merge([], ...array_map(self::typeReferences(...), $ts ?? []));
        return match ($type->form) {
            'reference' => [$type->name ?? ''],
            'shape' => $all(array_map(static fn (Field $f): TypeExpr => $f->type, $type->fields ?? [])),
            'case' => $all([$type->otherwise, ...array_map(static fn (array $b): TypeExpr => $b[1], $type->branches ?? [])]),
            'generic' => self::typeReferences($type->base),
            'refine' => [
                ...self::typeReferences($type->base),
                ...self::typeReferences(($type->constraints['contains'] ?? null) instanceof TypeExpr ? $type->constraints['contains'] : null),
            ],
            'list-items' => $all(array_merge([], ...array_map(static fn (Particle $p): array => $p->types(), $type->particles ?? []))),
            'apply', 'tuple-of' => $all($type->elems),
            'one-of', 'any-of', 'all-of' => $all($type->variants),
            'map-of', 'ordered-map-of' => [...self::typeReferences($type->key), ...self::typeReferences($type->value)],
            default => self::typeReferences($type->elem),
        };
    }

    /** @param list<string> $extern */
    private static function isExtern(string $name, array $extern): bool
    {
        $slash = strpos($name, '/');
        if ($slash === false) {
            return false;
        }
        $namespace = substr($name, 0, $slash);
        foreach ($extern as $e) {
            if ($namespace === $e || str_starts_with($namespace, "{$e}.")) {
                return true;
            }
        }
        return false;
    }

    // ---- lines ---------------------------------------------------------------------

    /** @return Problem */
    private static function problem(string $file, int $line, string $message): array
    {
        return ['file' => $file, 'line' => $line, 'message' => $message];
    }

    /** The line a code-point offset (the lexer's) falls on. */
    private static function lineAt(string $source, ?int $offset): int
    {
        if ($offset === null || $offset < 0) {
            return 1;
        }
        return substr_count(mb_substr($source, 0, $offset, 'UTF-8'), "\n") + 1;
    }

    /**
     * The line an entry's key stands on.
     *
     * @param TreeFile $file
     */
    private static function entryLine(array $file, string $key): int
    {
        $k = preg_quote($key, '/');
        return self::firstLine($file['source'], "/^\\s*(?:\"{$k}\"\\s*=>|:?{$k}\\??\\s*:)/m") ?? 1;
    }

    /**
     * The first line that mentions `$name`, as a written reference.
     *
     * @param TreeFile $file
     */
    private static function mentionLine(array $file, string $name): int
    {
        $parts = explode('/', $name);
        foreach ([$name, end($parts)] as $candidate) {
            $c = preg_quote((string) $candidate, '/');
            $line = self::firstLine($file['source'], "/(?<![\\w.\\/]){$c}(?![\\w.])/");
            if ($line !== null) {
                return $line;
            }
        }
        return 1;
    }

    private static function firstLine(string $source, string $pattern): ?int
    {
        if (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        return substr_count(substr($source, 0, $m[0][1]), "\n") + 1;
    }
}
