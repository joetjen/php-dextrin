<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNSet;
use PHPUnit\Framework\TestCase;

final class DXNSetTest extends TestCase
{
    public function testDedupesAtConstruction(): void
    {
        $set = new DXNSet([1, 2, 2, 3, 1]);
        self::assertCount(3, $set->items);
    }

    public function testDedupesStructurallyEqualCompoundItemsAcrossDistinctInstances(): void
    {
        $set = new DXNSet([new \JOetjen\Dextrin\Value\DXNSymbol('a'), new \JOetjen\Dextrin\Value\DXNSymbol('a')]);
        self::assertCount(1, $set->items);
    }

    public function testEqualsRegardlessOfConstructionOrder(): void
    {
        self::assertTrue((new DXNSet([1, 2, 3]))->equals(new DXNSet([3, 2, 1])));
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $set = DXNSet::fromArray([1, 2, 3]);
        self::assertCount(3, $set->toArray());
    }

    public function testDxnType(): void
    {
        self::assertSame('set', (new DXNSet([]))->dxnType());
    }
}
