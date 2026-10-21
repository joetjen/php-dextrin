<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema\Docs;

/**
 * Which comment belongs to which definition, in a `.dxns` document's
 * syntax tree (Jan, 2026-10-20) -- the port of
 * `Dextrin.Schema.Docs.Comments`:
 *
 * - **the block of comment lines directly above an entry** belongs to it,
 *   when no blank line separates the block from the entry;
 * - **a comment on the entry's own line**, after it, belongs to it too,
 *   and so does every comment line straight below that one.
 *
 * A block a blank line cuts off from what follows belongs to nothing
 * there; before a document's value, it is the document's own description.
 * Here the trivia is the source between two tokens -- a *gap* -- where the
 * reference has trivia tokens; the answers are the same.
 *
 * @internal
 */
final class Comments
{
    private function __construct()
    {
    }

    /** A comment's text: without its `#` and the one space after it. */
    public static function commentText(string $comment): string
    {
        $text = rtrim($comment);
        if (str_starts_with($text, '#')) {
            $text = substr($text, 1);
        }
        if (str_starts_with($text, ' ')) {
            $text = substr($text, 1);
        }
        return $text;
    }

    /**
     * The comments in `$text`, the trivia before a document's value: a blank
     * line between them, like a bare `#`, separates paragraphs.
     */
    public static function leading(string $text): string
    {
        $out = [];
        foreach (explode("\n", $text) as $raw) {
            $line = trim($raw);
            if (!str_starts_with($line, '#') && $line !== '') {
                continue;
            }
            $text = $line === '' ? '' : self::commentText($line);
            // A run of empty lines, blank or a bare `#`, is one paragraph break.
            if ($text === '' && $out !== [] && $out[count($out) - 1] === '') {
                continue;
            }
            $out[] = $text;
        }
        return trim(implode("\n", $out));
    }

    /**
     * For each entry of a container, in order, its comment lines: those
     * above it, then those after it. `$parts` is the container's inside --
     * the gaps between its tokens, and its entries -- in order.
     *
     * @param list<array{gap: string}|array{entry: true}> $parts
     * @return list<array{above: list<string>, after: list<string>}>
     */
    public static function attached(array $parts): array
    {
        $done = [];
        $current = null;
        $block = [];
        $newlines = 0;
        $onLine = false;
        foreach ($parts as $part) {
            if (isset($part['entry'])) {
                $above = $newlines <= 1 ? $block : [];
                if ($current !== null) {
                    $done[] = $current;
                }
                $current = ['above' => $above, 'after' => []];
                $block = [];
                $newlines = 0;
                $onLine = true;
                continue;
            }
            preg_match_all('/#[^\n]*|\n/', $part['gap'], $events);
            foreach ($events[0] as $event) {
                if ($event === "\n") {
                    $newlines++;
                    $onLine = false;
                } elseif ($onLine && $current !== null) {
                    $current['after'][] = self::commentText($event);
                } elseif ($current !== null && $current['after'] !== [] && $newlines === 1 && $block === []) {
                    // A comment straight below an entry's trailing comment continues it.
                    $current['after'][] = self::commentText($event);
                    $newlines = 0;
                } else {
                    if ($newlines >= 2) {
                        $block = [];
                    }
                    $block[] = self::commentText($event);
                    $newlines = 0;
                }
            }
        }
        if ($current !== null) {
            $done[] = $current;
        }
        return $done;
    }
}
