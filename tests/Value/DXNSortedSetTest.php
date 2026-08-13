<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use PHPUnit\Framework\TestCase;

final class DXNSortedSetTest extends TestCase
{
    public function testSortsItemsAtConstruction(): void
    {
        self::assertSame([1, 2, 3], (new DXNSortedSet([3, 1, 2]))->items);
    }

    public function testDeduplicatesPrimitiveItems(): void
    {
        self::assertSame([1, 2, 3], (new DXNSortedSet([1, 2, 2, 3, 1]))->items);
    }

    public function testEqualsRegardlessOfConstructionOrder(): void
    {
        self::assertTrue((new DXNSortedSet([3, 1, 2]))->equals(new DXNSortedSet([1, 2, 3])));
    }

    public function testRoundTripsThroughToArrayFromArrayAndToSetFromSet(): void
    {
        $sorted = DXNSortedSet::fromArray([3, 1, 2]);
        self::assertSame([1, 2, 3], $sorted->toArray());

        $set = $sorted->toSet();
        self::assertInstanceOf(DXNSet::class, $set);
        self::assertTrue(DXNSortedSet::fromSet($set)->equals($sorted));
    }

    public function testDxnType(): void
    {
        self::assertSame('sorted-set', (new DXNSortedSet([]))->dxnType());
    }
}
