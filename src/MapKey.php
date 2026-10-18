<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNUuid;

/**
 * Which values may be map keys (DXN.md §1.6): a string, keyword, integer,
 * uuid, date, bytes, symbol or char. Anything else is refused as
 * `key/not-allowed` -- in text, in binary and when encoding.
 *
 * The allowed types are those whose identity is exact and obvious in
 * every language. Floats, decimals and rationals are not; neither are
 * times, URIs or collections; `null` and the booleans collide with other
 * keys as PHP array keys (`null` with `""`, `true` with `1`) and are rare
 * as keys anyway.
 */
final class MapKey
{
    private function __construct()
    {
    }

    public static function allowed(mixed $key): bool
    {
        return is_string($key)
            || is_int($key)
            || $key instanceof DXNInteger
            || $key instanceof DXNKeyword
            || $key instanceof DXNUuid
            || $key instanceof DXNDate
            || $key instanceof DXNBytes
            || $key instanceof DXNSymbol
            || $key instanceof DXNChar;
    }

    /** @throws KeyNotAllowed */
    public static function check(mixed $key): void
    {
        if (!self::allowed($key)) {
            throw KeyNotAllowed::of($key);
        }
    }

    /**
     * @param iterable<array{0: mixed, 1: mixed}> $pairs
     * @throws KeyNotAllowed
     */
    public static function checkPairs(iterable $pairs): void
    {
        foreach ($pairs as [$key]) {
            self::check($key);
        }
    }
}
