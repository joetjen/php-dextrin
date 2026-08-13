<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNDate;
use PHPUnit\Framework\TestCase;

final class DXNDateTest extends TestCase
{
    public function testConstructsAValidDate(): void
    {
        $d = new DXNDate(2024, 6, 1);
        self::assertSame(2024, $d->year);
        self::assertSame(6, $d->month);
        self::assertSame(1, $d->day);
    }

    public function testRejectsOutOfRangeMonth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNDate(2024, 13, 1);
    }

    public function testRejectsInvalidDayForMonth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNDate(2024, 4, 31);
    }

    public function testCenturyLeapYearRules(): void
    {
        // Divisible by 400 -> leap; divisible by 100 but not 400 -> not leap.
        self::assertSame(29, (new DXNDate(2000, 2, 29))->day);
        $this->expectException(\InvalidArgumentException::class);
        new DXNDate(1900, 2, 29);
    }

    public function testToDateTimeImmutableAndBackRoundTrips(): void
    {
        $d = new DXNDate(2024, 6, 1);
        $back = DXNDate::fromDateTimeImmutable($d->toDateTimeImmutable());
        self::assertTrue($d->equals($back));
    }

    public function testEqualsAndCompareTo(): void
    {
        self::assertTrue((new DXNDate(2024, 6, 1))->equals(new DXNDate(2024, 6, 1)));
        self::assertLessThan(0, (new DXNDate(2024, 1, 1))->compareTo(new DXNDate(2024, 6, 1)));
    }

    public function testDxnType(): void
    {
        self::assertSame('date', (new DXNDate(2024, 1, 1))->dxnType());
    }
}
