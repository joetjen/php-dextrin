<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Document;
use JOetjen\Dextrin\Schema\Docs\At;
use JOetjen\Dextrin\Schema\Docs\Comments;
use JOetjen\Dextrin\Schema\Docs\Definition;
use JOetjen\Dextrin\Schema\Docs\Field;
use JOetjen\Dextrin\Schema\Docs\Html;
use JOetjen\Dextrin\Schema\Docs\Version;
use JOetjen\Dextrin\Text\Parser;
use JOetjen\Dextrin\Text\SyntaxNode;
use JOetjen\Dextrin\Text\Token;

/**
 * HTML documentation for a directory of `.dxns` schema documents -- the
 * port of `Dextrin.Schema.Docs`, writing the same site byte for byte (Jan,
 * 2026-10-20): a sidebar tree of every namespace and definition, the index
 * and every namespace's page in one design, the pages a directory tree with
 * an `index.html` per namespace, every reference a link, every version
 * shown. It reads the documents' source, not their compiled schemas.
 *
 * **Names** come from where a file lies under the root, by DXN.md §4.6:
 * `acme/shop/Customer-v2.dxns`, `acme/shop/Order/v1.dxns` and
 * `acme.shop.Product.dxns` each hold one definition, the entry named like
 * the file; every other entry in such a file is internal to it.
 * `acme.common.dxns`, whose entries are not named like it, is a namespace
 * file: each entry is `acme.common/<entry>`.
 */
final class Docs
{
    private function __construct()
    {
    }

    /**
     * The documentation of every `.dxns` file under `$root`, as pages: each
     * its path under the output directory, and its content. `$title` names
     * the site (the root directory's name when null). Throws, naming the
     * file, when one cannot be read.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function site(string $root, ?string $title = null): array
    {
        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            /** @var \SplFileInfo $file */
            if (str_ends_with($file->getFilename(), '.dxns')) {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(rtrim($root, '/')) + 1));
            }
        }
        sort($files, SORT_STRING);
        $read = [];
        foreach ($files as $file) {
            try {
                $read[] = self::readFile($root, $file);
            } catch (\Throwable $err) {
                throw new \RuntimeException("{$file}: {$err->getMessage()}", 0, $err);
            }
        }
        $real = realpath($root);
        return Html::pages($title ?? basename($real === false ? $root : $real), self::model($read));
    }

    /**
     * Writes the documentation of every `.dxns` file under `$root` to
     * `$out`; answers the paths it wrote.
     *
     * @return list<string>
     */
    public static function write(string $root, string $out, ?string $title = null): array
    {
        $written = [];
        foreach (self::site($root, $title) as [$page, $content]) {
            $target = "{$out}/{$page}";
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            file_put_contents($target, $content);
            $written[] = $target;
        }
        return $written;
    }

    // ---- reading ------------------------------------------------------------

    /**
     * One file: where its names come from, what it says about itself, and
     * its entries.
     *
     * @return array{file: string, stem: list<string>, fileVersion: ?int, leading: string, entries: list<array{key: string, value: ?At, doc: string, scope: At}>}
     */
    private static function readFile(string $root, string $file): array
    {
        $doc = Document::parse((string) file_get_contents("{$root}/{$file}"));
        $value = null;
        $header = null;
        foreach ($doc->tree->children as $child) {
            if ($child->kind === 'value') {
                $value = $child;
            } elseif ($child->kind === 'header') {
                $header = $child;
            }
        }
        $map = $value === null ? null : self::bodyOf($value);
        if ($value === null || $map === null || $map->kind !== 'map') {
            throw new \RuntimeException('a .dxns document is a map of names to schemas and types');
        }
        $at = new At($doc->source, $doc->tokens, $map);
        [$stem, $version] = self::stemOf($file);
        return [
            'file' => $file,
            'stem' => $stem,
            'fileVersion' => $version,
            'leading' => self::leadingOf($doc->source, $doc->tokens, $header, $value),
            'entries' => self::entriesOf($at),
        ];
    }

    /**
     * The comments before a document's value; its header's tokens stand
     * for a line break.
     *
     * @param list<Token> $tokens
     */
    private static function leadingOf(string $source, array $tokens, ?SyntaxNode $header, SyntaxNode $value): string
    {
        $end = $tokens[$value->first]->start;
        if ($header === null) {
            return Comments::leading(substr($source, 0, $end));
        }
        $before = substr($source, 0, $tokens[$header->first]->start);
        $from = $tokens[$header->last]->end;
        return Comments::leading("{$before}\n\n\n" . substr($source, $from, $end - $from));
    }

    /** The node a `value` holds: a collection, a struct, a tag, or a scalar's body. */
    public static function bodyOf(SyntaxNode $value): ?SyntaxNode
    {
        if ($value->kind !== 'value') {
            return null;
        }
        foreach ($value->children as $child) {
            if ($child->kind === 'body') {
                return $child->children[0] ?? $child;
            }
        }
        return null;
    }

    /**
     * A container's inside as gaps and entries, in order.
     *
     * @return list<array{gap: string}|array{entry: true}>
     */
    private static function partsOf(At $at): array
    {
        $node = $at->node;
        $parts = [];
        $from = $at->tokens[(int) $node->open]->end;
        foreach ($node->children as $child) {
            if ($child->kind !== 'entry') {
                continue;
            }
            $parts[] = ['gap' => substr($at->source, $from, $at->tokens[$child->first]->start - $from)];
            $parts[] = ['entry' => true];
            $from = $at->tokens[$child->last]->end;
        }
        $parts[] = ['gap' => substr($at->source, $from, $at->tokens[$node->last]->start - $from)];
        return $parts;
    }

    /** @return list<array{key: string, value: ?At, doc: string, scope: At}> */
    private static function entriesOf(At $map): array
    {
        $entries = array_values(array_filter($map->node->children, static fn (SyntaxNode $c): bool => $c->kind === 'entry'));
        $comments = Comments::attached(self::partsOf($map));
        $out = [];
        foreach ($entries as $i => $entry) {
            $value = self::valueOf($entry);
            $out[] = [
                'key' => self::keyOf($map, $entry),
                'value' => $value === null ? null : $map->at($value),
                'doc' => self::docText($comments[$i] ?? null),
                'scope' => $map,
            ];
        }
        return $out;
    }

    /** @param ?array{above: list<string>, after: list<string>} $c */
    private static function docText(?array $c): string
    {
        return $c === null ? '' : trim(implode("\n", [...$c['above'], ...$c['after']]));
    }

    /** The text of the token at `$index`. */
    public static function tokenText(At $at, int $index): string
    {
        $t = $at->tokens[$index];
        return substr($at->source, $t->start, $t->end - $t->start);
    }

    /** The tokens of `$node`, written together without the trivia between them. */
    public static function text(At $at, SyntaxNode $node): string
    {
        $out = '';
        for ($i = $node->first; $i <= $node->last; $i++) {
            $out .= self::tokenText($at, $i);
        }
        return $out;
    }

    private static function keyOf(At $at, SyntaxNode $entry): string
    {
        if ($entry->key !== null) {
            return rtrim(self::tokenText($at, $entry->key), ':');
        }
        foreach ($entry->children as $child) {
            if ($child->kind === 'value') {
                return trim(self::text($at, $child), '"');
            }
        }
        return '';
    }

    /** An entry's value: its last value child. */
    public static function valueOf(SyntaxNode $entry): ?SyntaxNode
    {
        $last = null;
        foreach ($entry->children as $child) {
            if ($child->kind === 'value') {
                $last = $child;
            }
        }
        return $last;
    }

    /**
     * A file's dotted stem and the version its name says.
     *
     * @return array{0: list<string>, 1: ?int}
     */
    private static function stemOf(string $file): array
    {
        $parts = explode('/', (string) preg_replace('/\.dxns$/', '', $file));
        $last = (string) array_pop($parts);
        $version = null;
        if ($parts !== [] && preg_match('/^v[1-9][0-9]*$/', $last) === 1) {
            $nameParts = $parts;
            $version = (int) substr($last, 1);
        } elseif (preg_match('/^(.+)-v([1-9][0-9]*)$/', $last, $m) === 1) {
            $nameParts = [...$parts, $m[1]];
            $version = (int) $m[2];
        } else {
            $nameParts = [...$parts, $last];
        }
        $stem = [];
        foreach ($nameParts as $part) {
            foreach (explode('.', $part) as $segment) {
                $stem[] = $segment;
            }
        }
        return [$stem, $version];
    }

    // ---- the model ------------------------------------------------------------

    /**
     * @param list<array{file: string, stem: list<string>, fileVersion: ?int, leading: string, entries: list<array{key: string, value: ?At, doc: string, scope: At}>}> $files
     * @return list<Definition>
     */
    private static function model(array $files): array
    {
        $byName = [];
        foreach ($files as $file) {
            foreach (self::definitionsOf($file) as $definition) {
                $byName[$definition->name][] = $definition;
            }
        }
        $merged = array_map(self::merge(...), array_values($byName));
        usort($merged, static fn (Definition $a, Definition $b): int => strcmp($a->name, $b->name));
        return $merged;
    }

    public static function namespaceOf(string $name): string
    {
        $slash = strpos($name, '/');
        return $slash === false ? '' : substr($name, 0, $slash);
    }

    /**
     * @param array{file: string, stem: list<string>, fileVersion: ?int, leading: string, entries: list<array{key: string, value: ?At, doc: string, scope: At}>} $file
     * @return list<Definition>
     */
    private static function definitionsOf(array $file): array
    {
        // The names every reader of a tree gives them ({@see Layout},
        // DXN.md §4.6).
        $layout = Layout::of($file['file'], array_map(static fn (array $e): string => $e['key'], $file['entries']));
        $names = $layout->names;
        $own = $layout->holder;
        $holder = $own;
        $owner = $own === null ? null : $names[$own];
        $out = [];
        foreach ($file['entries'] as $entry) {
            $name = $names[$entry['key']];
            $internalTo = $holder !== null && $entry['key'] !== $own ? $owner : null;
            [$kind, $version, $fields] = self::classify($entry['value'], $file['fileVersion']);
            $out[] = new Definition(
                $name,
                $entry['key'],
                $internalTo !== null ? self::namespaceOf($internalTo) : self::namespaceOf($name),
                $kind,
                $internalTo,
                $entry['doc'],
                $names,
                $holder === null ? $file['leading'] : null,
                [new Version(
                    $version,
                    $file['file'],
                    $holder !== null && $entry['key'] === $own ? $file['leading'] : null,
                    $fields,
                    $entry['value'],
                )],
            );
        }
        return $out;
    }

    /** The name of a struct node: the identifier after its `%`. */
    private static function structName(At $at, ?SyntaxNode $node): ?string
    {
        if ($node === null || $node->kind !== 'struct') {
            return null;
        }
        for ($i = $node->first; $i <= $node->last; $i++) {
            if ($at->tokens[$i]->type === 'IDENTIFIER') {
                return self::tokenText($at, $i);
            }
        }
        return null;
    }

    /** @return array{0: 'schema'|'shape'|'type', 1: ?int, 2: list<Field>} */
    private static function classify(?At $value, ?int $fileVersion): array
    {
        $node = $value === null ? null : self::bodyOf($value->node);
        $name = $value === null ? null : self::structName($value, $node);
        if ($value !== null && $node !== null && $name === 'schema') {
            return ['schema', self::headerVersion($value, $node) ?? $fileVersion ?? 1, self::fieldsOf($value->at($node))];
        }
        if ($value !== null && $node !== null && $name === 'shape') {
            return ['shape', null, self::fieldsOf($value->at($node))];
        }
        return ['type', null, []];
    }

    private static function headerVersion(At $at, SyntaxNode $struct): ?int
    {
        $end = $struct->last + 1;
        foreach ($struct->children as $child) {
            if ($child->kind === 'keyed' || $child->kind === 'positional') {
                $end = $child->first;
                break;
            }
        }
        for ($i = $struct->first; $i < $end; $i++) {
            if ($at->tokens[$i]->type === 'INTEGER') {
                return (int) self::tokenText($at, $i);
            }
        }
        return null;
    }

    /** @return list<Field> */
    private static function fieldsOf(At $struct): array
    {
        $keyed = null;
        foreach ($struct->node->children as $child) {
            if ($child->kind === 'keyed') {
                $keyed = $struct->at($child);
                break;
            }
        }
        if ($keyed === null) {
            return [];
        }
        $comments = Comments::attached(self::partsOf($keyed));
        $out = [];
        $i = 0;
        foreach ($keyed->node->children as $entry) {
            if ($entry->kind !== 'entry') {
                continue;
            }
            $raw = self::keyOf($keyed, $entry);
            $optional = str_ends_with($raw, '?');
            $out[] = self::field($keyed, $optional ? substr($raw, 0, -1) : $raw, $optional, self::valueOf($entry), self::docText($comments[$i] ?? null), $i + 1);
            $i++;
        }
        return $out;
    }

    private static function field(At $at, string $name, bool $optional, ?SyntaxNode $value, string $doc, int $position): Field
    {
        $struct = $value === null ? null : self::bodyOf($value);
        $keyed = null;
        if ($struct !== null && self::structName($at, $struct) === 'field') {
            foreach ($struct->children as $child) {
                if ($child->kind === 'keyed') {
                    $keyed = $child;
                }
            }
        }
        if ($keyed === null) {
            return new Field($name, $optional, $value === null ? null : $at->at($value), null, null, $doc, $position);
        }
        $parts = [];
        foreach ($keyed->children as $entry) {
            if ($entry->kind === 'entry') {
                $parts[self::keyOf($at, $entry)] = self::valueOf($entry);
            }
        }
        $node = static fn (string $k): ?At => isset($parts[$k]) ? $at->at($parts[$k]) : null;
        $description = $parts['description'] ?? null;
        return new Field(
            $name,
            $optional,
            $node('type'),
            $node('default'),
            $description === null ? null : self::stringValue($at, $description),
            $doc,
            $position,
        );
    }

    private static function stringValue(At $at, SyntaxNode $node): string
    {
        try {
            $value = Parser::parse(self::text($at, $node));
            return is_string($value) ? $value : self::text($at, $node);
        } catch (\Throwable) {
            return self::text($at, $node);
        }
    }

    /** @param list<Definition> $parts */
    private static function merge(array $parts): Definition
    {
        $versions = [];
        foreach ($parts as $part) {
            foreach ($part->versions as $version) {
                $versions[] = $version;
            }
        }
        usort($versions, static fn (Version $a, Version $b): int => ($b->version ?? 0) <=> ($a->version ?? 0));
        $latest = $parts[0];
        foreach ($parts as $part) {
            if (($part->versions[0]->version ?? 0) > ($latest->versions[0]->version ?? 0)) {
                $latest = $part;
            }
        }
        return $latest->with($versions, $parts[0]->namespaceNote);
    }

    // ---- references -------------------------------------------------------------

    /**
     * The names the type `$at` refers to, as `$scope` resolves them.
     *
     * @param array<string, string> $scope
     * @return list<string>
     */
    public static function references(?At $at, array $scope): array
    {
        if ($at === null) {
            return [];
        }
        $found = [];
        $visit = static function (SyntaxNode $node) use (&$visit, &$found, $at, $scope): void {
            if (self::isSymbol($at, $node)) {
                $found[] = self::resolve(self::text($at, $node), $scope);
            } else {
                foreach ($node->children as $child) {
                    $visit($child);
                }
            }
        };
        $visit($at->node);
        return $found;
    }

    /** Whether `$node` is a symbol: a body of one identifier. */
    public static function isSymbol(At $at, SyntaxNode $node): bool
    {
        return $node->kind === 'body' && $node->children === [] && $node->first === $node->last
            && $at->tokens[$node->first]->type === 'IDENTIFIER';
    }

    /**
     * The name a bare or namespaced `$name` stands for, in a file whose
     * entries are `$scope`.
     *
     * @param array<string, string> $scope
     */
    public static function resolve(string $name, array $scope): string
    {
        return $scope[$name] ?? $name;
    }
}
