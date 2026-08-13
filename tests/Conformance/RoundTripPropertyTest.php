<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Conformance;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Tests\Support\RandomValueGenerator;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNSortedSet;
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
            $value = RandomValueGenerator::value($rng, 0);
            $encoded = Dextrin::encode($value);
            $decoded = Dextrin::decode($encoded);
            self::assertTrue(
                Equality::dxnEquals($value, $decoded),
                "round-trip failed for iteration {$i}: " . $encoded,
            );
        }
    }

    public function testSortedSetRoundTrips(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
        for ($i = 0; $i < 50; $i++) {
            $value = DXNSortedSet::fromArray(RandomValueGenerator::items($rng, 2));
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
                $pairs[] = [new DXNKeyword(RandomValueGenerator::randomIdentifier($rng)), RandomValueGenerator::value($rng, 2)];
            }
            $value = new DXNOrderedMap($pairs);
            $decoded = Dextrin::decode(Dextrin::encode($value));
            self::assertTrue(Equality::dxnEquals($value, $decoded));
        }
    }
}
