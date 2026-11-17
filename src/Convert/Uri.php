<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Convert;

/**
 * A reference resolved against a base URI (RFC 3986 §5.2), as the
 * reference's `URI.merge/2` resolves it: what the JSON Schema converter
 * needs to find one schema from another. Nothing is fetched.
 *
 * @internal
 */
final class Uri
{
    private function __construct()
    {
    }

    /**
     * `$ref` resolved against `$base`, or `null` when `$base` is no
     * absolute URI to resolve against.
     */
    public static function merge(string $base, string $ref): ?string
    {
        $b = self::parse($base);
        $r = self::parse($ref);
        if ($b['scheme'] === null) {
            return null;
        }
        if ($r['scheme'] !== null) {
            return self::compose([...$r, 'path' => self::removeDots($r['path'])]);
        }
        if ($r['authority'] !== null) {
            return self::compose([...$r, 'scheme' => $b['scheme'], 'path' => self::removeDots($r['path'])]);
        }
        if ($r['path'] === '') {
            return self::compose([...$b, 'query' => $r['query'] ?? $b['query'], 'fragment' => $r['fragment']]);
        }
        if (str_starts_with($r['path'], '/')) {
            $path = self::removeDots($r['path']);
        } elseif ($b['authority'] !== null && $b['path'] === '') {
            $path = self::removeDots('/' . $r['path']);
        } else {
            $at = strrpos($b['path'], '/');
            $path = self::removeDots(($at === false ? '' : substr($b['path'], 0, $at + 1)) . $r['path']);
        }
        return self::compose([...$b, 'path' => $path, 'query' => $r['query'], 'fragment' => $r['fragment']]);
    }

    /**
     * RFC 3986's appendix B, keeping an empty authority (`file:///a`)
     * apart from none.
     *
     * @return array{scheme: ?string, authority: ?string, path: string, query: ?string, fragment: ?string}
     */
    private static function parse(string $uri): array
    {
        preg_match('~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~s', $uri, $m, PREG_UNMATCHED_AS_NULL);
        return [
            'scheme' => $m[1] ?? null,
            'authority' => $m[2] ?? null,
            'path' => $m[3] ?? '',
            'query' => $m[4] ?? null,
            'fragment' => $m[5] ?? null,
        ];
    }

    /** @param array{scheme: ?string, authority: ?string, path: string, query: ?string, fragment: ?string} $u */
    private static function compose(array $u): string
    {
        return ($u['scheme'] === null ? '' : $u['scheme'] . ':')
            . ($u['authority'] === null ? '' : '//' . $u['authority'])
            . $u['path']
            . ($u['query'] === null ? '' : '?' . $u['query'])
            . ($u['fragment'] === null ? '' : '#' . $u['fragment']);
    }

    /** RFC 3986 §5.2.4: `.` and `..` segments taken out of `$path`. */
    private static function removeDots(string $path): string
    {
        $out = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            if ($segment === '.') {
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            if ($segment === '..') {
                if (count($out) > 1 || (count($out) === 1 && $out[0] !== '')) {
                    array_pop($out);
                }
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            $out[] = $segment;
        }
        return implode('/', $out);
    }
}
