<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNTime;
use PHPUnit\Framework\TestCase;

final class DXNTimeTest extends TestCase
{
    public function testConstructsAValidTimeOfDay(): void
    {
        $t = new DXNTime(13, 45, 30, 123456);
        self::assertSame(13, $t->hour);
        self::assertSame(45, $t->minute);
        self::assertSame(30, $t->second);
        self::assertSame(123456, $t->microsecond);
    }

    public function testDefaultsMicrosecondToZero(): void
    {
        self::assertSame(0, (new DXNTime(0, 0, 0))->microsecond);
    }

    public function testRejectsOutOfRangeFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNTime(24, 0, 0);
    }

    public function testEqualsAndCompareTo(): void
    {
        self::assertTrue((new DXNTime(1, 2, 3))->equals(new DXNTime(1, 2, 3)));
        self::assertLessThan(0, (new DXNTime(1, 0, 0))->compareTo(new DXNTime(2, 0, 0)));
    }

    public function testDxnType(): void
    {
        self::assertSame('time', (new DXNTime(0, 0, 0))->dxnType());
    }
}
