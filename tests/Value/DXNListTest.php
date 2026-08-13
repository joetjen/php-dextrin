<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNList;
use PHPUnit\Framework\TestCase;

final class DXNListTest extends TestCase
{
    public function testWrapsItems(): void
    {
        self::assertSame([1, 2, 3], (new DXNList([1, 2, 3]))->items);
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $list = DXNList::fromArray([1, 2, 3]);
        self::assertSame([1, 2, 3], $list->toArray());
    }

    public function testEqualsIsOrderSensitiveAndRecursive(): void
    {
        self::assertTrue((new DXNList([1, 2]))->equals(new DXNList([1, 2])));
        self::assertFalse((new DXNList([1, 2]))->equals(new DXNList([2, 1])));
        self::assertTrue((new DXNList([new DXNList([1])]))->equals(new DXNList([new DXNList([1])])));
    }

    public function testDistinctFromArrayEvenWithSameItems(): void
    {
        // list stays wrapped specifically so it's distinguishable from
        // a bare native PHP array (which claims DXN `array` instead).
        $list = new DXNList([1, 2, 3]);
        self::assertNotSame([1, 2, 3], $list);
        self::assertIsArray($list->items);
    }

    public function testDxnType(): void
    {
        self::assertSame('list', (new DXNList([]))->dxnType());
    }
}
