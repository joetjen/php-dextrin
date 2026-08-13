<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Binary;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Tests\Support\RandomValueGenerator;
use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\Equality;
use PHPUnit\Framework\TestCase;

/**
 * The `.dxnb` analog of `Conformance\RoundTripPropertyTest` -- same
 * generator, same discipline, different pipeline
 * (`decodeBinary(encodeBinary(v))` instead of `decode(encode(v))`).
 *
 * One genuine, spec-mandated asymmetry from the text codec:
 * `.dxnb` structs are *always* positional on the wire
 * (`dxn/DXN.md` §2.1), so a keyed `DXNStruct` the generator produces
 * necessarily comes back positional (same field values, same name,
 * different `kind`) -- {@see self::normalizeForBinary()} accounts for
 * that before comparing, the same way `Encoder`'s own doc explains
 * why encoding a keyed struct falls back to its pairs' iteration
 * order.
 */
final class BinaryRoundTripPropertyTest extends TestCase
{
    private const ITERATIONS = 300;

    public function testDecodeBinaryOfEncodeBinaryIsStructurallyEqualForRandomValues(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20260814));
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $value = RandomValueGenerator::value($rng, 0);
            $encoded = Dextrin::encodeBinary($value);
            $decoded = Dextrin::decodeBinary($encoded);
            self::assertTrue(
                Equality::dxnEquals(self::normalizeForBinary($value), $decoded),
                "round-trip failed for iteration {$i}: " . bin2hex($encoded),
            );
        }
    }

    public function testSortedSetRoundTrips(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(43));
        for ($i = 0; $i < 50; $i++) {
            $value = DXNSortedSet::fromArray(RandomValueGenerator::items($rng, 2));
            $decoded = Dextrin::decodeBinary(Dextrin::encodeBinary($value));
            self::assertTrue(Equality::dxnEquals(self::normalizeForBinary($value), $decoded));
        }
    }

    public function testOrderedMapRoundTrips(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(8));
        for ($i = 0; $i < 50; $i++) {
            $pairs = [];
            for ($j = 0, $count = $rng->getInt(0, 4); $j < $count; $j++) {
                $pairs[] = [new DXNKeyword(RandomValueGenerator::randomIdentifier($rng)), RandomValueGenerator::value($rng, 2)];
            }
            $value = new DXNOrderedMap($pairs);
            $decoded = Dextrin::decodeBinary(Dextrin::encodeBinary($value));
            self::assertTrue(Equality::dxnEquals(self::normalizeForBinary($value), $decoded));
        }
    }

    private static function normalizeForBinary(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::normalizeForBinary(...), $value),
            $value instanceof DXNList => new DXNList(array_map(self::normalizeForBinary(...), $value->items)),
            $value instanceof DXNTuple => new DXNTuple(array_map(self::normalizeForBinary(...), $value->items)),
            $value instanceof DXNSet => new DXNSet(array_map(self::normalizeForBinary(...), $value->items)),
            $value instanceof DXNSortedSet => DXNSortedSet::fromArray(array_map(self::normalizeForBinary(...), $value->items)),
            $value instanceof DXNOrderedMap => new DXNOrderedMap(array_map(
                static fn (array $p): array => [self::normalizeForBinary($p[0]), self::normalizeForBinary($p[1])],
                $value->pairs,
            )),
            $value instanceof DXNStruct => DXNStruct::positional(
                $value->name,
                array_map(
                    self::normalizeForBinary(...),
                    $value->isPositional() ? $value->values() : array_map(static fn (array $p): mixed => $p[1], $value->pairs()),
                ),
            ),
            $value instanceof DXNCustomTag => new DXNCustomTag($value->name, self::normalizeForBinary($value->value)),
            default => $value,
        };
    }

    /**
     * Unlike the text codec (whose decimal *literal* grammar has no
     * exponent notation, see `RandomValueGenerator`'s own doc), CBOR
     * tag 4's `[exponent, mantissa]` payload supports any signed
     * exponent directly -- binary round-trips a positive-exponent
     * `DXNDecimal` exactly, the one place this port's two codecs
     * genuinely differ in fidelity.
     */
    public function testDecimalWithPositiveExponentRoundTripsExactlyThroughBinary(): void
    {
        $value = new DXNDecimal(-1, '912920', 2);
        $decoded = Dextrin::decodeBinary(Dextrin::encodeBinary($value));
        self::assertTrue(Equality::dxnEquals($value, $decoded));
        self::assertSame(2, $decoded->exponent);
        self::assertSame('912920', $decoded->unscaledValue);
    }
}
