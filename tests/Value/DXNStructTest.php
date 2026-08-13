<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

final class DXNStructTest extends TestCase
{
    public function testKeyedBuildsANameCarryingKeyedFieldsStruct(): void
    {
        $s = DXNStruct::keyed('Point', [['x', 1], ['y', 2]]);
        self::assertSame('Point', $s->name);
        self::assertTrue($s->isKeyed());
        self::assertSame([['x', 1], ['y', 2]], $s->pairs());
    }

    public function testPositionalBuildsANameCarryingPositionalFieldsStruct(): void
    {
        $s = DXNStruct::positional('Point', [1, 2]);
        self::assertTrue($s->isPositional());
        self::assertSame([1, 2], $s->values());
    }

    public function testKeepsKeyedAndPositionalShapesDistinct(): void
    {
        $keyed = DXNStruct::keyed('Point', [['x', 1]]);
        $this->expectException(\LogicException::class);
        $keyed->values();
    }

    public function testKeyedEqualityIsOrderIndependent(): void
    {
        $a = DXNStruct::keyed('Point', [['x', 1], ['y', 2]]);
        $b = DXNStruct::keyed('Point', [['y', 2], ['x', 1]]);
        self::assertTrue($a->equals($b));
    }

    public function testPositionalEqualityIsOrderSensitive(): void
    {
        $a = DXNStruct::positional('Point', [1, 2]);
        $b = DXNStruct::positional('Point', [2, 1]);
        self::assertFalse($a->equals($b));
    }

    public function testDifferentNamesAreNeverEqual(): void
    {
        $a = DXNStruct::keyed('Point', [['x', 1]]);
        $b = DXNStruct::keyed('Vector', [['x', 1]]);
        self::assertFalse($a->equals($b));
    }

    public function testDxnType(): void
    {
        self::assertSame('struct', DXNStruct::keyed('Point', [])->dxnType());
    }
}
