<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Document;

use JOetjen\Dextrin\Document;
use JOetjen\Dextrin\Text\SyntaxNode;

/**
 * A document laid out the way the pretty printer lays out a value --
 * every non-empty collection over lines, one entry per line, indented --
 * with every comment kept beside the entry it belongs to, mirroring
 * `Dextrin.Document.Formatter`.
 *
 * **Layout only.** Every token is written exactly as it was -- a
 * literal's spelling, a key's form, an escape -- and only the space
 * between tokens changes: indentation, line breaks, commas, and blank
 * lines, of which at most one is kept wherever the source had any
 * between entries. A document with no comments therefore comes out as
 * the pretty printer writes its value.
 *
 * **Where a comment goes**, by lines alone: a comment on the line of the
 * token or entry before it trails that, and so does every comment line
 * straight after it, aligned under its `#`; any other block of comment
 * lines goes above the next entry, or at the end of its collection when
 * none follows. A comment between a key or a tag and its value stays
 * between them, and the value moves to the next line.
 *
 * @phpstan-type Part array{gap: string}|array{node: SyntaxNode}
 */
final class Formatter
{
    private function __construct(private readonly Document $doc, private readonly string $step)
    {
    }

    /** The text of `$doc`, laid out, `$indent` spaces per level. */
    public static function format(Document $doc, int $indent = 2): string
    {
        $self = new self($doc, str_repeat(' ', $indent));
        $head = '';
        $from = 0;
        $value = null;
        foreach ($doc->tree->children as $child) {
            if ($child->kind === 'header') {
                $head .= $self->headerText($child->first, $child->last) . "\n";
                $from = $doc->tokens[$child->last]->end;
            } elseif ($child->kind === 'value') {
                $value = $child;
            }
        }
        if ($value === null) {
            return $head;
        }
        $sequence = [
            ['gap' => substr($doc->source, $from, $self->start($value) - $from)],
            ['node' => $value],
            ['gap' => substr($doc->source, $self->end($value))],
        ];
        // It ends with a newline exactly when the source did: a formatter
        // run on save keeps a file's last newline, and a comment-free
        // document without one comes out exactly as the pretty printer
        // writes its value.
        $ending = str_ends_with($doc->source, "\n") ? "\n" : '';
        return $head . $self->lines($self->slots($sequence, false), '', fn (SyntaxNode $node): string => $self->valueText($node, '')) . $ending;
    }

    // ---- slots: which comment belongs where -------------------------------

    /**
     * A container's inside, as what is laid out: each entry with the
     * comment blocks above it and the block trailing it, the blocks at its
     * end, and the block trailing whatever opened it when `$onLine` -- a
     * comment straight after `[` stays beside it.
     *
     * @param list<Part> $sequence
     * @return array{entries: list<Slot>, open: list<string>, terminal: list<Block>}
     */
    private function slots(array $sequence, bool $onLine): array
    {
        $entries = [];
        $open = [];
        $pending = [];
        $newlines = 0;
        $trailing = false;
        $target = null;

        foreach ($sequence as $part) {
            if (isset($part['node'])) {
                // A blank line straight before the entry: after its
                // comments, or, with none, after the entry before it --
                // never right after an opening bracket.
                $target = new Slot($part['node'], $pending, $newlines >= 2 && ($pending !== [] || $target !== null));
                $entries[] = $target;
                $pending = [];
                $newlines = 0;
                $onLine = true;
                $trailing = false;
                continue;
            }
            preg_match_all('/#[^\n]*|\n/', $part['gap'], $events);
            foreach ($events[0] as $event) {
                if ($event === "\n") {
                    $onLine = false;
                    $newlines++;
                } elseif ($onLine || ($trailing && $newlines === 1)) {
                    if ($target === null) {
                        $open[] = rtrim($event);
                    } else {
                        $target->trailing[] = rtrim($event);
                    }
                    $trailing = true;
                    $newlines = 0;
                } else {
                    $blank = $newlines >= 2 && $target !== null;
                    $last = $pending[count($pending) - 1] ?? null;
                    if ($last !== null && !$blank && $newlines <= 1) {
                        $last->lines[] = rtrim($event);
                    } else {
                        $pending[] = new Block($blank, [rtrim($event)]);
                    }
                    $trailing = false;
                    $newlines = 0;
                }
            }
        }
        return ['entries' => $entries, 'open' => $open, 'terminal' => $pending];
    }

    // ---- lines ------------------------------------------------------------

    /**
     * A container's entries and comments, one line each at `$indent`.
     *
     * @param array{entries: list<Slot>, open: list<string>, terminal: list<Block>} $laid
     * @param callable(SyntaxNode): string $render
     */
    private function lines(array $laid, string $indent, callable $render): string
    {
        $body = '';
        foreach ($laid['entries'] as $slot) {
            $body .= $this->aboveLines($slot->above, $indent) . ($slot->blank ? "\n" : '')
                . $this->trailed($indent . $render($slot->node), $slot->trailing) . "\n";
        }
        return rtrim($body . $this->aboveLines($laid['terminal'], $indent), "\n");
    }

    /** @param list<Block> $blocks */
    private function aboveLines(array $blocks, string $indent): string
    {
        $text = '';
        foreach ($blocks as $block) {
            $text .= ($block->blank ? "\n" : '') . implode('', array_map(static fn (string $line): string => "{$indent}{$line}\n", $block->lines));
        }
        return $text;
    }

    /**
     * `$text` with its trailing comments: the first two spaces after its
     * last line, the rest aligned under it.
     *
     * @param list<string> $comments
     */
    private function trailed(string $text, array $comments): string
    {
        if ($comments === []) {
            return $text;
        }
        $lines = explode("\n", $text);
        $pad = str_repeat(' ', mb_strlen($lines[count($lines) - 1]) + 2);
        $out = ["{$text}  {$comments[0]}"];
        foreach (array_slice($comments, 1) as $comment) {
            $out[] = $pad . $comment;
        }
        return implode("\n", $out);
    }

    // ---- values -----------------------------------------------------------

    private function valueText(SyntaxNode $node, string $indent): string
    {
        $parts = [];
        foreach ($node->children as $child) {
            if ($child->kind === 'discard') {
                $inner = Document::items($child)[0];
                $parts[] = '@_ ' . $this->valueText($inner, $indent);
            } else {
                $parts[] = $this->bodyText($child, $indent);
            }
        }
        return implode(' ', $parts);
    }

    private function bodyText(SyntaxNode $body, string $indent): string
    {
        $inner = $body->children[0] ?? null;
        if ($inner === null) {
            return $this->tokensText($body->first, $body->last);
        }
        if (in_array($inner->kind, ['list', 'tuple', 'set', 'map'], true)) {
            return $this->collection($inner, '', $indent);
        }
        if ($inner->kind === 'struct') {
            foreach ($inner->children as $part) {
                if ($part->kind === 'keyed' || $part->kind === 'positional') {
                    return $this->collection($part, $this->tokensText($inner->first, $part->first - 1), $indent);
                }
            }
        }
        if ($inner->kind === 'tag' && $inner->name !== null) {
            // A tag and its value. Written together, `@array[1 2]` -- the
            // spec's own spelling -- they stay together; with anything
            // between them, one space does.
            $value = Document::items($inner)[0];
            $name = $this->tokensText($inner->first, $inner->name);
            $nameEnd = $this->doc->tokens[$inner->name]->end;
            $gap = substr($this->doc->source, $nameEnd, $this->start($value) - $nameEnd);
            if ($gap === '') {
                return $name . $this->valueText($value, $indent);
            }
            return $this->between($name, self::commentsIn($gap), fn (string $deeper): string => $this->valueText($value, $deeper), $indent);
        }
        return $this->tokensText($inner->first, $inner->last);
    }

    /**
     * A collection: its opening tokens after `$head`, its entries one per
     * line a level deeper, its closing token -- or `[]` when it holds
     * nothing, not even a comment.
     */
    private function collection(SyntaxNode $node, string $head, string $indent): string
    {
        $openAt = (int) $node->open;
        $open = $head . $this->tokensText($node->first, $openAt);
        $close = $this->tokensText($node->last, $node->last);
        $sequence = [];
        $from = $this->doc->tokens[$openAt]->end;
        foreach ($node->children as $child) {
            $sequence[] = ['gap' => substr($this->doc->source, $from, $this->start($child) - $from)];
            $sequence[] = ['node' => $child];
            $from = $this->end($child);
        }
        $sequence[] = ['gap' => substr($this->doc->source, $from, $this->doc->tokens[$node->last]->start - $from)];
        $laid = $this->slots($sequence, true);
        if ($laid['entries'] === [] && $laid['open'] === [] && $laid['terminal'] === []) {
            return $open . $close;
        }
        $deeper = $indent . $this->step;
        $body = $this->lines($laid, $deeper, fn (SyntaxNode $child): string => $this->item($child, $deeper));
        return $this->trailed($open, $laid['open']) . "\n{$body}\n{$indent}{$close}";
    }

    private function item(SyntaxNode $node, string $indent): string
    {
        if ($node->kind !== 'entry') {
            return $this->valueText($node, $indent);
        }
        $values = Document::items($node);
        $value = $values[count($values) - 1];
        $render = fn (string $deeper): string => $this->valueText($value, $deeper);
        if ($node->key !== null) {
            $keyEnd = $this->doc->tokens[$node->key]->end;
            $gap = substr($this->doc->source, $keyEnd, $this->start($value) - $keyEnd);
            return $this->between($this->tokensText($node->key, $node->key), self::commentsIn($gap), $render, $indent);
        }
        $key = $values[0];
        $gap = substr($this->doc->source, $this->end($key), $this->start($value) - $this->end($key));
        return $this->between($this->valueText($key, $indent) . ' =>', self::commentsIn($gap), $render, $indent);
    }

    /**
     * `$prefix` and the value after it: on one line, or, with a comment
     * between them, the comment beside the prefix and the value on the
     * next line, a level deeper.
     *
     * @param list<string> $comments
     * @param callable(string): string $render
     */
    private function between(string $prefix, array $comments, callable $render, string $indent): string
    {
        if ($comments === []) {
            return "{$prefix} " . $render($indent);
        }
        $deeper = $indent . $this->step;
        return $this->trailed($prefix, $comments) . "\n{$deeper}" . $render($deeper);
    }

    /** @return list<string> */
    private static function commentsIn(string $gap): array
    {
        preg_match_all('/#[^\n]*/', $gap, $matches);
        return array_map('rtrim', $matches[0]);
    }

    /** The tokens `$first..$last`, written together without the trivia between them. */
    /**
     * The header, `@dxn "1.0"`: the tag, one space, and the version, each
     * as written, however they were spaced. The tag is two tokens here
     * (`@`, `dxn`), so the space goes before the version alone.
     */
    private function headerText(int $first, int $last): string
    {
        return $this->tokensText($first, $last - 1) . ' ' . $this->tokensText($last, $last);
    }

    private function tokensText(int $first, int $last): string
    {
        $text = '';
        for ($i = $first; $i <= $last; $i++) {
            $token = $this->doc->tokens[$i];
            $text .= substr($this->doc->source, $token->start, $token->end - $token->start);
        }
        return $text;
    }

    private function start(SyntaxNode $node): int
    {
        return $this->doc->tokens[$node->first]->start;
    }

    private function end(SyntaxNode $node): int
    {
        return $this->doc->tokens[$node->last]->end;
    }
}
