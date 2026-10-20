<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema\Docs;

/**
 * The Markdown a schema's comments are written in, as HTML -- the port of
 * `Dextrin.Schema.Docs.Markdown`, a small, fixed subset, so that every
 * implementation renders a comment the same: paragraphs, `- ` and `1. `
 * lists (an indented line continuing an item), and inline `code`,
 * `**strong**`, `*emphasis*` and `[text](https://...)` links. A code span
 * that names a definition becomes a link to it; `$link` answers the
 * address for a name, or null.
 *
 * @internal
 */
final class Markdown
{
    private function __construct()
    {
    }

    /** `$text` with the characters HTML gives a meaning to escaped. */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $text);
    }

    /** @param callable(string): ?string $link */
    public static function toHtml(string $text, callable $link): string
    {
        $blocks = [];
        $blankRun = null;
        foreach (explode("\n", $text) as $line) {
            $blank = trim($line) === '';
            if ($blank !== $blankRun) {
                $blocks[] = [];
            }
            $blocks[count($blocks) - 1][] = $line;
            $blankRun = $blank;
        }
        $html = [];
        foreach ($blocks as $lines) {
            if (trim($lines[0]) !== '') {
                $html[] = self::block($lines, $link);
            }
        }
        return implode("\n", $html);
    }

    /**
     * @param list<string> $lines
     * @param callable(string): ?string $link
     */
    private static function block(array $lines, callable $link): string
    {
        $first = $lines[0];
        if (str_starts_with($first, '- ')) {
            return self::listOf('ul', self::items($lines, '/^- /'), $link);
        }
        if (preg_match('/^\d+\. /', $first) === 1) {
            return self::listOf('ol', self::items($lines, '/^\d+\. /'), $link);
        }
        return '<p>' . self::inline(implode(' ', array_map('trim', $lines)), $link) . '</p>';
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function items(array $lines, string $marker): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (preg_match($marker, $line) === 1) {
                $out[] = (string) preg_replace($marker, '', $line, 1);
            } elseif ($out !== []) {
                $out[count($out) - 1] .= ' ' . trim($line);
            }
        }
        return $out;
    }

    /**
     * @param list<string> $items
     * @param callable(string): ?string $link
     */
    private static function listOf(string $tag, array $items, callable $link): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<li>' . self::inline($item, $link) . '</li>';
        }
        return "<{$tag}>{$html}</{$tag}>";
    }

    /** @param callable(string): ?string $link */
    public static function inline(string $text, callable $link): string
    {
        // Code spans first, so nothing inside one is read as markup.
        $parts = preg_split('/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';
        foreach ($parts as $part) {
            if (preg_match('/^`([^`]+)`$/', $part, $m) === 1) {
                $href = $link($m[1]);
                $html .= $href === null
                    ? '<code>' . self::escape($m[1]) . '</code>'
                    : '<a class="ref" href="' . self::escape($href) . '"><code>' . self::escape($m[1]) . '</code></a>';
            } else {
                $html .= self::markup(self::escape($part));
            }
        }
        return $html;
    }

    private static function markup(string $text): string
    {
        $text = (string) preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2">$1</a>', $text);
        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        return (string) preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
    }
}
