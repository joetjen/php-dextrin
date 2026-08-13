<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNTuple;
use PHPUnit\Framework\TestCase;

final class DXNTupleTest extends TestCase
{
    public function testWrapsItems(): void
    {
        self::assertSame([1, 2, 3], (new DXNTuple([1, 2, 3]))->items);
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        self::assertSame([1, 2, 3], DXNTuple::fromArray([1, 2, 3])->toArray());
    }

    public function testEqualsIsOrderSensitive(): void
    {
        self::assertTrue((new DXNTuple([1, 2]))->equals(new DXNTuple([1, 2])));
        self::assertFalse((new DXNTuple([1, 2]))->equals(new DXNTuple([2, 1])));
    }

    public function testDxnType(): void
    {
        self::assertSame('tuple', (new DXNTuple([]))->dxnType());
    }
}
