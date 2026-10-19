<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

use JOetjen\Dextrin\Document\DocumentError;
use JOetjen\Dextrin\Document\Formatter;
use JOetjen\Dextrin\Text\Parser;
use JOetjen\Dextrin\Text\SyntaxNode;
use JOetjen\Dextrin\Text\Token;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNMap;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\Equality;

/**
 * A `.dxn` document with everything it holds: its value, and every
 * comment, blank line and comma around it (`design/comment-retention.md`
 * in `dextrin`), mirroring `Dextrin.Document`.
 *
 * `Dextrin::decode()` keeps only the value, which is right for data and
 * wrong for a file people annotate: decoding, changing one entry and
 * encoding again deletes every comment. A document keeps the syntax tree
 * instead -- every token, with the trivia between them -- and the value
 * beside it. It is immutable: every edit answers a new document.
 *
 *     $doc = Document::parse($source);
 *     $doc->toValue();   // what decode() returns
 *     $doc->render();    // the source, byte for byte
 *
 * **One parser.** The tree is recorded by the same parser `decode()`
 * runs ({@see Parser::parseTree()}), so a document parses exactly when
 * `decode()` would, and refuses with the same errors.
 *
 * **Edits** -- {@see self::replace()}, {@see self::delete()},
 * {@see self::insert()} -- name what they change by a path from the
 * value: a map's key as it decodes (`new DXNKeyword('deps')`), a
 * struct's field name (`'x'`), a list's, tuple's or set's index. Each
 * splices the source at the edited node and parses the result again, so
 * every byte it does not touch is rendered as it was.
 *
 * **A comment belongs to an entry**, by lines alone: a block starting on
 * an entry's own line is its *trailing* block, however many lines it
 * runs to; every other block of comment lines is *above* the next entry,
 * and a blank line separates blocks. A deleted entry takes its trailing
 * block and the blocks above it; a replaced value keeps every comment
 * around it; an inserted entry goes above the comments of the entry it
 * lands before.
 *
 * {@see self::update()} turns a new value into these edits, leaving what
 * is equal alone, and refuses by default where guessing which edit was
 * meant would lose a comment.
 */
final class Document
{
    /** @param list<Token> $tokens */
    private function __construct(
        public readonly string $source,
        public readonly SyntaxNode $tree,
        public readonly array $tokens,
        private readonly mixed $value,
        private readonly ?Registry $registry,
        private readonly ?bool $strict,
    ) {
    }

    /**
     * Parses `$source`, keeping everything in it. `$registry` and
     * `$strict` are {@see Dextrin::decode()}'s and mean the same; so do
     * the errors.
     *
     * @throws DXNError when `$source` is not a document `decode()` accepts.
     */
    public static function parse(string $source, ?Registry $registry = null, ?bool $strict = null): self
    {
        try {
            $read = $registry !== null && $strict !== null ? $registry->putStrict($strict) : $registry;
            [$value, $tree, $tokens] = Parser::parseTree($source, $read);
            return new self($source, $tree, $tokens, $value, $registry, $strict);
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'parse');
        }
    }

    /** The value this document holds: what {@see Dextrin::decode()} gives for its source. */
    public function toValue(): mixed
    {
        return $this->value;
    }

    /** This document as `.dxn` text: its source, byte for byte, when nothing was edited. */
    public function render(): string
    {
        return $this->source;
    }

    /**
     * This document laid out the way the pretty printer lays out a value,
     * with every comment kept beside the entry it belongs to. Layout only:
     * every token is written as it was.
     */
    public function format(int $indent = 2): string
    {
        return Formatter::format($this, $indent);
    }

    // ---- edits ------------------------------------------------------------

    /**
     * This document with the value at `$path` replaced by `$value`,
     * written as {@see Dextrin::encode()} writes it -- over lines at the
     * indentation it replaces when it needs more than one. Every comment
     * around it stays.
     *
     * @param list<mixed> $path
     * @throws DocumentError when `$path` leads nowhere.
     */
    public function replace(array $path, mixed $value): self
    {
        $found = $this->located($path);
        [$from, $to] = $this->span(self::bodyNode($found['value']));
        return $this->spliced($from, $to, $this->written($value, $this->indentation($from)));
    }

    /**
     * This document without the entry at `$path` -- a map's key and
     * value, a list's item -- and without the comments that belong to it.
     *
     * @param list<mixed> $path
     * @throws DocumentError when `$path` leads nowhere.
     */
    public function delete(array $path): self
    {
        $found = $this->located($path);
        [$from, $to] = $this->ownedSpan($found['entry'], $found['siblings']);
        return $this->spliced($from, $to, '');
    }

    /**
     * This document with `$value` added to the collection at `$path`:
     * before the item at `$where` in a list, tuple or set (`-1` for the
     * end), or under the new key `$where` in a map or a struct, after its
     * last entry. A key already there is refused; that is
     * {@see self::replace()}'s.
     *
     * @param list<mixed> $path
     * @throws DocumentError when `$path` leads nowhere, to no collection, or `$where` is a key already there.
     */
    public function insert(array $path, mixed $where, mixed $value): self
    {
        $collection = $this->collectionAt($path);
        $keyed = $collection->kind === 'map' || $collection->kind === 'keyed';
        if ($keyed) {
            foreach (self::entries($collection) as $entry) {
                if (Equality::dxnEquals($this->keyOf($entry, $collection->kind), $where)) {
                    throw new DocumentError('exists', [...$path, $where]);
                }
            }
        }
        $text = $keyed ? $this->entryText($collection, $where, $value) : $this->written($value, '');
        return $this->inserted($collection, $where, $text);
    }

    /**
     * This document holding `$value`, by the fewest edits that make it,
     * and those edits. Lists compare item by item along their longest
     * common subsequence. A deleted item followed by an inserted one is
     * ambiguous -- a replace keeps the deleted one's comments, a delete
     * and an insert drop them -- and `$onAmbiguity` decides: `'safe'`
     * reads a replace where the deleted item owns no comment and refuses
     * where it does; `'guess'` always reads a replace; `'error'` refuses
     * every one.
     *
     * @return array{0: self, 1: list<array{op: string, path: list<mixed>, where?: mixed, value?: mixed}>}
     * @throws DocumentError when an ambiguity is refused.
     */
    public function update(mixed $value, string $onAmbiguity = 'safe'): array
    {
        if (!in_array($onAmbiguity, ['safe', 'guess', 'error'], true)) {
            throw new \InvalidArgumentException("unknown onAmbiguity \"{$onAmbiguity}\"");
        }
        $changes = $this->changesBetween([], $this->value, $value, $onAmbiguity);
        $doc = $this;
        foreach ($changes as $change) {
            $doc = match ($change['op']) {
                'replace' => $doc->replace($change['path'], $change['value'] ?? null),
                'delete' => $doc->delete($change['path']),
                default => $doc->insert($change['path'], $change['where'] ?? null, $change['value'] ?? null),
            };
        }
        return [$doc, $changes];
    }

    // ---- update's edits -----------------------------------------------------

    /**
     * The edits that turn `$old`, at `$path`, into `$now`. Paths are where
     * the edit applies once every edit before it has been made.
     *
     * @param list<mixed> $path
     * @return list<array{op: string, path: list<mixed>, where?: mixed, value?: mixed}>
     */
    private function changesBetween(array $path, mixed $old, mixed $now, string $mode): array
    {
        if (Equality::dxnEquals($old, $now)) {
            return [];
        }
        $oldPairs = self::mapPairs($old);
        $nowPairs = self::mapPairs($now);
        if ($oldPairs !== null && $nowPairs !== null) {
            return $this->keyedChanges($path, $oldPairs, $nowPairs, $mode);
        }
        if (
            $old instanceof DXNStruct && $now instanceof DXNStruct && $old->name === $now->name
            && $old->version === $now->version && $old->isKeyed() && $now->isKeyed()
        ) {
            return $this->keyedChanges($path, $old->pairs(), $now->pairs(), $mode);
        }
        if ($old instanceof DXNList && $now instanceof DXNList) {
            return $this->listedChanges($path, $old->items, $now->items, $mode);
        }
        return [['op' => 'replace', 'path' => $path, 'value' => $now]];
    }

    /**
     * A map's pairs, whatever shape it decoded to, or null for anything
     * that is not a map.
     *
     * @return list<array{0: mixed, 1: mixed}>|null
     */
    private static function mapPairs(mixed $value): ?array
    {
        if ($value instanceof DXNMap) {
            return $value->pairs();
        }
        if (is_array($value) && ($value === [] || !array_is_list($value))) {
            // A native array holds a keyword key as its bare name; the
            // document's key is the keyword.
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = [MapBuilder::toDxnMapKey($key), $item];
            }
            return $pairs;
        }
        return null;
    }

    /**
     * A map's or a keyed struct's entries: the keys gone are deleted, the
     * keys kept are compared, the keys new are inserted after the last.
     *
     * @param list<mixed> $path
     * @param list<array{0: mixed, 1: mixed}> $old
     * @param list<array{0: mixed, 1: mixed}> $now
     * @return list<array{op: string, path: list<mixed>, where?: mixed, value?: mixed}>
     */
    private function keyedChanges(array $path, array $old, array $now, string $mode): array
    {
        $find = static function (array $pairs, mixed $key): ?array {
            foreach ($pairs as $pair) {
                if (Equality::dxnEquals($pair[0], $key)) {
                    return $pair;
                }
            }
            return null;
        };
        $gone = [];
        $kept = [];
        $added = [];
        foreach ($old as [$key, $value]) {
            $match = $find($now, $key);
            if ($match === null) {
                $gone[] = ['op' => 'delete', 'path' => [...$path, $key]];
            } else {
                array_push($kept, ...$this->changesBetween([...$path, $key], $value, $match[1], $mode));
            }
        }
        foreach ($now as [$key, $value]) {
            if ($find($old, $key) === null) {
                $added[] = ['op' => 'insert', 'path' => $path, 'where' => $key, 'value' => $value];
            }
        }
        return [...$gone, ...$kept, ...$added];
    }

    /**
     * A list's edit script, item by item: `$at` is where the next edit
     * applies, `$was` where the item it looks at sat in the document as
     * parsed -- which is where its comments are.
     *
     * @param list<mixed> $path
     * @param list<mixed> $old
     * @param list<mixed> $now
     * @return list<array{op: string, path: list<mixed>, where?: mixed, value?: mixed}>
     */
    private function listedChanges(array $path, array $old, array $now, string $mode): array
    {
        $changes = [];
        $at = 0;
        $was = 0;
        foreach (self::script($old, $now) as $run) {
            if ($run['op'] === 'eq') {
                $at += $run['count'];
                $was += $run['count'];
                continue;
            }
            // A deleted run followed by an inserted one pairs item for item.
            $paired = min(count($run['items']), count($run['inserted']));
            for ($i = 0; $i < $paired; $i++) {
                if ($mode === 'error' || ($mode === 'safe' && $this->ownsComment([...$path, $was]))) {
                    throw new DocumentError('ambiguous', [...$path, $was]);
                }
                array_push($changes, ...$this->changesBetween([...$path, $at], $run['items'][$i], $run['inserted'][$i], $mode));
                $at++;
                $was++;
            }
            for ($i = $paired; $i < count($run['items']); $i++) {
                $changes[] = ['op' => 'delete', 'path' => [...$path, $at]];
                $was++;
            }
            foreach (array_slice($run['inserted'], $paired) as $value) {
                $changes[] = ['op' => 'insert', 'path' => $path, 'where' => $at, 'value' => $value];
                $at++;
            }
        }
        return $changes;
    }

    /**
     * `$old` into `$now` as runs along their longest common subsequence:
     * an equal run, or a deleted run with whatever was inserted in its
     * place (possibly nothing), or an inserted run alone.
     *
     * @param list<mixed> $old
     * @param list<mixed> $now
     * @return list<array{op: 'eq', count: int, items: list<mixed>, inserted: list<mixed>}|array{op: 'change', count: int, items: list<mixed>, inserted: list<mixed>}>
     */
    private static function script(array $old, array $now): array
    {
        $n = count($old);
        $m = count($now);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = Equality::dxnEquals($old[$i], $now[$j])
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $steps = [];
        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && Equality::dxnEquals($old[$i], $now[$j])) {
                $steps[] = 'eq';
                $i++;
                $j++;
            } elseif ($j >= $m || ($i < $n && $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
                $steps[] = 'del';
                $i++;
            } else {
                $steps[] = 'ins';
                $j++;
            }
        }
        $runs = [];
        $i = 0;
        $j = 0;
        $k = 0;
        while ($k < count($steps)) {
            if ($steps[$k] === 'eq') {
                $count = 0;
                while ($k < count($steps) && $steps[$k] === 'eq') {
                    $count++;
                    $k++;
                }
                $runs[] = ['op' => 'eq', 'count' => $count, 'items' => [], 'inserted' => []];
                $i += $count;
                $j += $count;
                continue;
            }
            $items = [];
            $inserted = [];
            while ($k < count($steps) && $steps[$k] !== 'eq') {
                if ($steps[$k] === 'del') {
                    $items[] = $old[$i++];
                } else {
                    $inserted[] = $now[$j++];
                }
                $k++;
            }
            $runs[] = ['op' => 'change', 'count' => 0, 'items' => $items, 'inserted' => $inserted];
        }
        return $runs;
    }

    /** @param list<mixed> $path */
    private function ownsComment(array $path): bool
    {
        $found = $this->located($path);
        [$from, $to] = $this->ownedSpan($found['entry'], $found['siblings']);
        foreach ($this->comments() as [$c]) {
            if ($c >= $from && $c < $to) {
                return true;
            }
        }
        return false;
    }

    // ---- finding a path -----------------------------------------------------

    /**
     * What a path leads to: the `value` node it names, the node standing
     * for the whole entry, and the entries beside it.
     *
     * @param list<mixed> $path
     * @return array{value: SyntaxNode, entry: SyntaxNode, siblings: list<SyntaxNode>}
     */
    private function located(array $path): array
    {
        $root = null;
        foreach ($this->tree->children as $child) {
            if ($child->kind === 'value') {
                $root = $child;
            }
        }
        if ($root === null) {
            throw new DocumentError('not-found', $path);
        }
        $found = ['value' => $root, 'entry' => $root, 'siblings' => [$root]];
        foreach ($path as $depth => $step) {
            $next = $this->stepInto(self::collectionNode($found['value']), $step);
            if ($next === null) {
                throw new DocumentError('not-found', array_slice($path, 0, $depth + 1));
            }
            $found = $next;
        }
        return $found;
    }

    /** @return array{value: SyntaxNode, entry: SyntaxNode, siblings: list<SyntaxNode>}|null */
    private function stepInto(?SyntaxNode $node, mixed $step): ?array
    {
        if ($node === null) {
            return null;
        }
        if ($node->kind === 'map' || $node->kind === 'keyed' || ($node->kind === 'positional' && !is_int($step))) {
            $all = self::entries($node);
            $kind = $node->kind === 'map' ? 'map' : 'keyed';
            // The key exactly, or -- as a native array spells a keyword
            // key, by its bare name -- the keyword of that name.
            foreach ([$step, is_string($step) && $kind === 'map' ? new DXNKeyword($step) : null] as $wanted) {
                foreach ($all as $entry) {
                    if ($wanted !== null && Equality::dxnEquals($this->keyOf($entry, $kind), $wanted)) {
                        return ['value' => self::entryValue($entry), 'entry' => $entry, 'siblings' => $all];
                    }
                }
            }
            return null;
        }
        if (in_array($node->kind, ['list', 'tuple', 'set', 'positional'], true) && is_int($step)) {
            $all = self::items($node);
            return isset($all[$step]) ? ['value' => $all[$step], 'entry' => $all[$step], 'siblings' => $all] : null;
        }
        return null;
    }

    /** @param list<mixed> $path */
    private function collectionAt(array $path): SyntaxNode
    {
        $node = self::collectionNode($this->located($path)['value']);
        if ($node === null || !in_array($node->kind, ['map', 'keyed', 'positional', 'list', 'tuple', 'set'], true)) {
            throw new DocumentError('not-a-collection', $path);
        }
        return $node;
    }

    /** The collection or scalar a `value` node holds, past its discards; a struct's keyed or positional body. */
    private static function collectionNode(SyntaxNode $value): ?SyntaxNode
    {
        $inner = self::bodyNode($value)->children[0] ?? null;
        if ($inner === null || $inner->kind !== 'struct') {
            return $inner;
        }
        foreach ($inner->children as $child) {
            if ($child->kind === 'keyed' || $child->kind === 'positional') {
                return $child;
            }
        }
        return null;
    }

    private static function bodyNode(SyntaxNode $value): SyntaxNode
    {
        foreach ($value->children as $child) {
            if ($child->kind === 'body') {
                return $child;
            }
        }
        throw new \LogicException('a value node always has a body');
    }

    /** @return list<SyntaxNode> */
    public static function entries(SyntaxNode $node): array
    {
        return array_values(array_filter($node->children, static fn (SyntaxNode $c): bool => $c->kind === 'entry'));
    }

    /** @return list<SyntaxNode> */
    public static function items(SyntaxNode $node): array
    {
        return array_values(array_filter($node->children, static fn (SyntaxNode $c): bool => $c->kind === 'value'));
    }

    private static function entryValue(SyntaxNode $entry): SyntaxNode
    {
        $values = self::items($entry);
        return $values[count($values) - 1];
    }

    /**
     * A map entry's key as it decodes: a shorthand `name:` is the keyword
     * `:name` in a map and the field name `'name'` in a struct; an arrow
     * entry's key is its value.
     */
    private function keyOf(SyntaxNode $entry, string $kind): mixed
    {
        if ($entry->key !== null) {
            $name = (string) $this->tokens[$entry->key]->value;
            return $kind === 'map' ? new DXNKeyword($name) : $name;
        }
        [$from, $to] = $this->span($entry->children[0]);
        return Parser::parse(substr($this->source, $from, $to - $from), $this->registry);
    }

    /**
     * A node's `[from, to)` byte range in the source.
     *
     * @return array{0: int, 1: int}
     */
    public function span(SyntaxNode $node): array
    {
        return [$this->tokens[$node->first]->start, $this->tokens[$node->last]->end];
    }

    // ---- comments and lines -------------------------------------------------

    /**
     * Every comment in the document, as `[from, to)` -- the `#` to the end
     * of its line, newline excluded -- found in the trivia between
     * tokens, where a `#` can only begin one.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function comments(): array
    {
        $found = [];
        $from = 0;
        foreach ($this->tokens as $token) {
            $gap = substr($this->source, $from, $token->start - $from);
            if (preg_match_all('/#[^\n]*/', $gap, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [$text, $at]) {
                    $found[] = [$from + $at, $from + $at + strlen($text)];
                }
            }
            $from = $token->end;
        }
        return $found;
    }

    /**
     * The bytes an entry owns: the blocks above it and its trailing block,
     * whole lines, when it stands on a line of its own; itself and the
     * separator beside it when it shares its line.
     *
     * @param list<SyntaxNode> $siblings
     * @return array{0: int, 1: int}
     */
    private function ownedSpan(SyntaxNode $entry, array $siblings): array
    {
        $all = $this->comments();
        [$from, $to] = $this->span($entry);
        if ($this->aloneBefore($from) && $this->aloneAfter($all, $to)) {
            return [$this->aboveStart($all, $from), $this->trailingEnd($all, $to)];
        }
        $last = $siblings[count($siblings) - 1];
        if ($this->span($last)[1] === $to) {
            $before = substr($this->source, 0, $from);
            return [$from - (strlen($before) - strlen(rtrim($before, " \t,"))), $to];
        }
        return [$from, $to + strspn($this->source, " \t,", $to)];
    }

    /** Whether nothing but whitespace and commas precedes `$at` on its line. */
    private function aloneBefore(int $at): bool
    {
        $start = $this->lineStart($at);
        return preg_match('/^[ \t,]*$/', substr($this->source, $start, $at - $start)) === 1;
    }

    /**
     * Whether nothing but whitespace, commas and a comment follows `$at` on its line.
     *
     * @param list<array{0: int, 1: int}> $all
     */
    private function aloneAfter(array $all, int $at): bool
    {
        $end = $this->lineEnd($at);
        $stop = $end;
        foreach ($all as [$c]) {
            if ($c >= $at && $c < $end) {
                $stop = $c;
                break;
            }
        }
        return preg_match('/^[ \t,]*$/', substr($this->source, $at, $stop - $at)) === 1;
    }

    /**
     * The end of an entry's trailing block -- the comment on its own line
     * and every comment line straight after it -- or of its own line.
     *
     * @param list<array{0: int, 1: int}> $all
     */
    private function trailingEnd(array $all, int $at): int
    {
        $end = $this->lineEnd($at);
        foreach ($all as [$c, $cTo]) {
            if ($c >= $at && $c < $end) {
                return $this->afterNewline($this->blockEnd($all, $cTo));
            }
        }
        return $this->afterNewline($end);
    }

    /** @param list<array{0: int, 1: int}> $all */
    private function blockEnd(array $all, int $to): int
    {
        $nextLine = $this->afterNewline($to);
        foreach ($all as [$c, $cTo]) {
            if ($c >= $nextLine && $this->aloneBefore($c)) {
                return $this->lineStart($c) === $nextLine ? $this->blockEnd($all, $cTo) : $to;
            }
        }
        return $to;
    }

    /**
     * Where an entry's above blocks start: the comment lines straight
     * above it, blank lines between blocks allowed, up to a line of code
     * -- less a topmost block that continues the previous entry's
     * trailing comment with no blank line between.
     *
     * @param list<array{0: int, 1: int}> $all
     */
    private function aboveStart(array $all, int $at): int
    {
        $ownStart = $this->lineStart($at);
        $above = [];
        $cursor = $ownStart;
        foreach (array_reverse(array_filter($all, static fn (array $c): bool => $c[1] <= $ownStart)) as $comment) {
            $gap = substr($this->source, $comment[1], $cursor - $comment[1]);
            if (!$this->aloneBefore($comment[0]) || preg_match('/^[ \t,\r\n]*$/', $gap) !== 1) {
                break;
            }
            array_unshift($above, $comment);
            $cursor = $this->lineStart($comment[0]);
        }
        $blocks = $this->blocksOf($above);
        if ($blocks !== []) {
            $top = $this->lineStart($blocks[0][0][0]);
            if ($top > 0) {
                $previous = $this->lineStart($top - 1);
                foreach ($all as [$c]) {
                    if ($c >= $previous && $c < $top && !$this->aloneBefore($c)) {
                        array_shift($blocks);
                        break;
                    }
                }
            }
        }
        return $blocks !== [] ? $this->lineStart($blocks[0][0][0]) : $ownStart;
    }

    /**
     * Comments, top to bottom, as blocks: a blank line ends one.
     *
     * @param list<array{0: int, 1: int}> $list
     * @return list<list<array{0: int, 1: int}>>
     */
    private function blocksOf(array $list): array
    {
        $blocks = [];
        foreach ($list as $comment) {
            $last = count($blocks) - 1;
            if ($last >= 0) {
                $block = $blocks[$last];
                $previousEnd = $block[count($block) - 1][1];
                if (substr_count(substr($this->source, $previousEnd, $comment[0] - $previousEnd), "\n") <= 1) {
                    $blocks[$last][] = $comment;
                    continue;
                }
            }
            $blocks[] = [$comment];
        }
        return $blocks;
    }

    private function lineStart(int $at): int
    {
        $found = $at > 0 ? strrpos(substr($this->source, 0, $at), "\n") : false;
        return $found === false ? 0 : $found + 1;
    }

    private function lineEnd(int $at): int
    {
        $found = strpos($this->source, "\n", $at);
        return $found === false ? strlen($this->source) : $found;
    }

    private function afterNewline(int $at): int
    {
        return $at < strlen($this->source) ? $at + 1 : $at;
    }

    private function indentation(int $at): string
    {
        $start = $this->lineStart($at);
        return substr($this->source, $start, strspn($this->source, " \t", $start));
    }

    // ---- writing ------------------------------------------------------------

    /** `$value` as `.dxn` text, its continuation lines at `$indent`. */
    private function written(mixed $value, string $indent): string
    {
        return str_replace("\n", "\n{$indent}", Dextrin::encode($value, pretty: true, registry: $this->registry));
    }

    /** The text of a new map entry, `key: value`, as the pretty printer writes a one-entry map. */
    private function entryText(SyntaxNode $collection, mixed $key, mixed $value): string
    {
        $named = $collection->kind === 'keyed' && is_string($key) ? new DXNKeyword($key) : $key;
        $lines = array_slice(explode("\n", $this->written(new DXNMap([[$named, $value]]), '')), 1, -1);
        return implode("\n", array_map(static fn (string $line): string => (string) preg_replace('/^ {2}/', '', $line), $lines));
    }

    private function inserted(SyntaxNode $collection, mixed $where, string $text): self
    {
        $all = $this->comments();
        $keyed = $collection->kind === 'map' || $collection->kind === 'keyed';
        $elements = $keyed ? self::entries($collection) : self::items($collection);
        $index = !$keyed && is_int($where) && $where >= 0 ? min($where, count($elements)) : count($elements);
        $before = $elements[$index] ?? null;
        $close = $this->tokens[$collection->last]->start;
        $multiline = $elements !== []
            ? $this->aloneBefore($this->span($elements[0])[0]) && $this->aloneAfter($all, $this->span($elements[0])[1])
            : $this->lineStart($this->tokens[$collection->first]->start) !== $this->lineStart($close);
        $indented = static fn (string $indent): string => $indent . str_replace("\n", "\n{$indent}", $text) . "\n";

        if ($multiline && $before !== null) {
            $at = $this->aboveStart($all, $this->span($before)[0]);
            return $this->spliced($at, $at, $indented($this->indentation($this->span($before)[0])));
        }
        if ($multiline && $elements !== []) {
            $last = $this->span($elements[count($elements) - 1]);
            $at = $this->trailingEnd($all, $last[1]);
            return $this->spliced($at, $at, $indented($this->indentation($last[0])));
        }
        if ($multiline) {
            $at = $this->lineStart($close);
            return $this->spliced($at, $at, $indented($this->indentation($close) . '  '));
        }
        if ($before !== null) {
            $at = $this->span($before)[0];
            return $this->spliced($at, $at, "{$text} ");
        }
        if ($elements !== []) {
            $at = $this->span($elements[count($elements) - 1])[1];
            return $this->spliced($at, $at, " {$text}");
        }
        return $this->spliced($close, $close, $text);
    }

    /** This document with `[$from, $to)` of its text replaced by `$text`, parsed again with the options it was parsed with. */
    private function spliced(int $from, int $to, string $text): self
    {
        return self::parse(substr($this->source, 0, $from) . $text . substr($this->source, $to), $this->registry, $this->strict);
    }
}
