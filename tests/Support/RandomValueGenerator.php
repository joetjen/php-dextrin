<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Support;

use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNLocalDateTime;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTime;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUri;
use JOetjen\Dextrin\Value\DXNUuid;

/**
 * Hand-rolled random `DXNValue` tree generator -- this project's
 * property-testing convention (no dedicated package, see `AGENTS.md`),
 * shared by the text and binary round-trip property tests so both
 * exercise the same input distribution rather than drifting apart.
 *
 * The `decimal` case restricts its exponent to `<= 0` even though
 * that's only a *text*-format limitation (`dxn/DXN.md`'s decimal
 * *literal* grammar has no exponent notation, unlike CBOR tag 4's
 * `[exponent, mantissa]` payload, which supports any signed exponent
 * directly) -- kept restricted here anyway so one generator serves
 * both pipelines; `Binary\BinaryRoundTripPropertyTest` covers the
 * positive-exponent case on its own, specifically to demonstrate
 * binary's better fidelity there.
 */
final class RandomValueGenerator
{
    private function __construct()
    {
    }

    public static function value(\Random\Randomizer $rng, int $depth): mixed
    {
        $maxKind = $depth >= 3 ? 14 : 20; // stop nesting past depth 3
        return match ($rng->getInt(0, $maxKind)) {
            0 => null,
            1 => (bool) $rng->getInt(0, 1),
            2 => $rng->getInt(-1000, 1000),
            3 => DXNInteger::represent(self::randomBigDigits($rng)),
            4 => self::randomFloat($rng),
            5 => new DXNDecimal($rng->getInt(0, 1) === 0 ? 1 : -1, (string) $rng->getInt(0, 999999), $rng->getInt(-5, 0)),
            6 => new DXNRational((string) $rng->getInt(-1000, 1000), (string) $rng->getInt(1, 1000)),
            7 => self::randomString($rng),
            8 => new DXNChar($rng->getInt(0x20, 0x7e)),
            9 => new DXNSymbol(self::randomIdentifier($rng)),
            10 => new DXNKeyword(self::randomIdentifier($rng)),
            11 => new DXNUuid($rng->getBytes(16)),
            12 => new DXNUri('https://example.com/' . self::randomIdentifier($rng)),
            13 => new DXNBytes($rng->getInt(0, 3) === 0 ? '' : $rng->getBytes($rng->getInt(1, 8))),
            14 => new DXNDate($rng->getInt(1, 2900), $rng->getInt(1, 12), $rng->getInt(1, 28)),
            15 => self::randomClock($rng),
            16 => new DXNList(self::items($rng, $depth)),
            17 => new DXNTuple(self::items($rng, $depth)),
            18 => self::items($rng, $depth), // bare array -> DXN `array`
            19 => new DXNSet(self::items($rng, $depth)),
            20 => self::structOrMap($rng, $depth),
            default => null,
        };
    }

    /**
     * A time, datetime or local date-time, to the nanosecond (DXN.md
     * §1.3): epoch nanoseconds kept well inside the 64-bit range, so the
     * wall clock a datetime's offset moves it to fits too.
     */
    private static function randomClock(\Random\Randomizer $rng): DXNTime|DXNDateTime|DXNLocalDateTime
    {
        $nanos = $rng->getInt(-4_000_000_000_000_000_000, 4_000_000_000_000_000_000);
        return match ($rng->getInt(0, 2)) {
            0 => new DXNTime($rng->getInt(0, 23), $rng->getInt(0, 59), $rng->getInt(0, 59), $rng->getInt(0, 999_999_999)),
            1 => new DXNDateTime($nanos, $rng->getInt(-1439, 1439)),
            default => new DXNLocalDateTime($nanos),
        };
    }

    /** @return list<mixed> */
    public static function items(\Random\Randomizer $rng, int $depth): array
    {
        $count = $rng->getInt(0, 4);
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = self::value($rng, $depth + 1);
        }
        return $items;
    }

    private static function structOrMap(\Random\Randomizer $rng, int $depth): mixed
    {
        if ($rng->getInt(0, 1) === 0) {
            /** @var array<string, mixed> $map */
            $map = [];
            $count = $rng->getInt(0, 3);
            for ($i = 0; $i < $count; $i++) {
                $map[self::randomIdentifier($rng)] = self::value($rng, $depth + 1);
            }
            return $map;
        }
        $pairs = [];
        $count = $rng->getInt(0, 3);
        for ($i = 0; $i < $count; $i++) {
            $pairs[] = [self::randomIdentifier($rng), self::value($rng, $depth + 1)];
        }
        return DXNStruct::keyed(self::randomIdentifier($rng, ucfirst: true), $pairs);
    }

    public static function randomIdentifier(\Random\Randomizer $rng, bool $ucfirst = false): string
    {
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        $len = $rng->getInt(1, 8);
        $s = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= $letters[$rng->getInt(0, 25)];
        }
        return $ucfirst ? ucfirst($s) : $s;
    }

    private static function randomString(\Random\Randomizer $rng): string
    {
        $chars = "abc \"'\\\n\t123";
        $len = $rng->getInt(0, 10);
        $s = '';
        $charsLen = strlen($chars);
        for ($i = 0; $i < $len; $i++) {
            $s .= $chars[$rng->getInt(0, $charsLen - 1)];
        }
        return $s;
    }

    private static function randomFloat(\Random\Randomizer $rng): float
    {
        return $rng->getInt(-100000, 100000) / 100.0;
    }

    /** @return numeric-string */
    private static function randomBigDigits(\Random\Randomizer $rng): string
    {
        $len = $rng->getInt(20, 30);
        $s = (string) $rng->getInt(1, 9);
        for ($i = 1; $i < $len; $i++) {
            $s .= (string) $rng->getInt(0, 9);
        }
        return $rng->getInt(0, 1) === 0 ? $s : "-{$s}";
    }
}
