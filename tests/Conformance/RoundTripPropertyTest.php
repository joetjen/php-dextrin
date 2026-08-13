<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Conformance;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTime;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUri;
use JOetjen\Dextrin\Value\DXNUuid;
use JOetjen\Dextrin\Value\Equality;
use PHPUnit\Framework\TestCase;

/**
 * Hand-rolled randomized round-trip test over the full value model --
 * this project's convention (no dedicated property-testing package,
 * see `AGENTS.md`) for a space of inputs too large to usefully
 * enumerate by hand. `decode(encode(v))` must equal `v` structurally
 * for an arbitrary, randomly generated value tree, run many times
 * with a fixed seed for reproducibility.
 */
final class RoundTripPropertyTest extends TestCase
{
    private const ITERATIONS = 300;

    public function testDecodeOfEncodeIsStructurallyEqualForRandomValues(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20260813));
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $value = self::randomValue($rng, 0);
            $encoded = Dextrin::encode($value);
            $decoded = Dextrin::decode($encoded);
            self::assertTrue(
                Equality::dxnEquals($value, $decoded),
                "round-trip failed for iteration {$i}: " . $encoded,
            );
        }
    }

    private static function randomValue(\Random\Randomizer $rng, int $depth): mixed
    {
        $maxKind = $depth >= 3 ? 14 : 20; // stop nesting past depth 3
        return match ($rng->getInt(0, $maxKind)) {
            0 => null,
            1 => (bool) $rng->getInt(0, 1),
            2 => $rng->getInt(-1000, 1000),
            3 => DXNInteger::represent(self::randomBigDigits($rng)),
            4 => self::randomFloat($rng),
            // exponent stays <= 0: DXN's decimal *literal* grammar
            // (digit{digit}(.digit{digit})?M) has no exponent
            // notation, so a positive exponent (trailing zeros before
            // an implicit decimal point) has no text form distinct
            // from the same magnitude normalized to exponent 0 --
            // this port's own "never auto-reduced" representation,
            // same category as DXNRational's, just not reachable via
            // this format specifically.
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
            15 => new DXNTime($rng->getInt(0, 23), $rng->getInt(0, 59), $rng->getInt(0, 59), $rng->getInt(0, 999999)),
            16 => new DXNList(self::randomItems($rng, $depth)),
            17 => new DXNTuple(self::randomItems($rng, $depth)),
            18 => self::randomItems($rng, $depth), // bare array -> DXN `array`
            19 => new DXNSet(self::randomItems($rng, $depth)),
            20 => self::randomStructOrMap($rng, $depth),
            default => null,
        };
    }

    /** @return list<mixed> */
    private static function randomItems(\Random\Randomizer $rng, int $depth): array
    {
        $count = $rng->getInt(0, 4);
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = self::randomValue($rng, $depth + 1);
        }
        return $items;
    }

    private static function randomStructOrMap(\Random\Randomizer $rng, int $depth): mixed
    {
        if ($rng->getInt(0, 1) === 0) {
            /** @var array<string, mixed> $map */
            $map = [];
            $count = $rng->getInt(0, 3);
            for ($i = 0; $i < $count; $i++) {
                $map[self::randomIdentifier($rng)] = self::randomValue($rng, $depth + 1);
            }
            return $map;
        }
        $pairs = [];
        $count = $rng->getInt(0, 3);
        for ($i = 0; $i < $count; $i++) {
            $pairs[] = [self::randomIdentifier($rng), self::randomValue($rng, $depth + 1)];
        }
        return DXNStruct::keyed(self::randomIdentifier($rng, ucfirst: true), $pairs);
    }

    private static function randomIdentifier(\Random\Randomizer $rng, bool $ucfirst = false): string
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

    public function testSortedSetRoundTrips(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
        for ($i = 0; $i < 50; $i++) {
            $value = DXNSortedSet::fromArray(self::randomItems($rng, 2));
            $decoded = Dextrin::decode(Dextrin::encode($value));
            self::assertTrue(Equality::dxnEquals($value, $decoded));
        }
    }

    public function testOrderedMapRoundTrips(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(7));
        for ($i = 0; $i < 50; $i++) {
            $pairs = [];
            for ($j = 0, $count = $rng->getInt(0, 4); $j < $count; $j++) {
                $pairs[] = [new DXNKeyword(self::randomIdentifier($rng)), self::randomValue($rng, 2)];
            }
            $value = new DXNOrderedMap($pairs);
            $decoded = Dextrin::decode(Dextrin::encode($value));
            self::assertTrue(Equality::dxnEquals($value, $decoded));
        }
    }
}
