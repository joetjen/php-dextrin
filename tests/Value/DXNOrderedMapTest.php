<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNOrderedMap;
use PHPUnit\Framework\TestCase;

final class DXNOrderedMapTest extends TestCase
{
    public function testWrapsPairsInOrder(): void
    {
        $om = new DXNOrderedMap([['b', 2], ['a', 1]]);
        self::assertSame([['b', 2], ['a', 1]], $om->pairs);
    }

    public function testOrderIsPartOfIdentity(): void
    {
        $om1 = new DXNOrderedMap([['a', 1], ['b', 2]]);
        $om2 = new DXNOrderedMap([['b', 2], ['a', 1]]);
        self::assertFalse($om1->equals($om2), 'ordered-map equality must be order-sensitive');
    }

    public function testToArrayFromArrayRoundTripForStringKeys(): void
    {
        $om = DXNOrderedMap::fromArray(['a' => 1, 'b' => 2]);
        self::assertSame(['a' => 1, 'b' => 2], $om->toArray());
    }

    public function testToArrayThrowsForNonScalarKeys(): void
    {
        $om = new DXNOrderedMap([[new DXNOrderedMap([]), 1]]);
        $this->expectException(\InvalidArgumentException::class);
        $om->toArray();
    }

    public function testDxnType(): void
    {
        self::assertSame('ordered-map', (new DXNOrderedMap([]))->dxnType());
    }
}
