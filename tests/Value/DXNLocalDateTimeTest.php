<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNLocalDateTime;
use PHPUnit\Framework\TestCase;

/** DXN `local-datetime`: a wall-clock date and time, no offset (Jan, 2026-10-20). */
final class DXNLocalDateTimeTest extends TestCase
{
    public function testWrapsNanosecondsSince1970OnTheWallClock(): void
    {
        self::assertSame(-1, (new DXNLocalDateTime(-1))->localNanoseconds);
    }

    public function testToDateTimeImmutableIsTheWallClockInTheZoneGiven(): void
    {
        $local = new DXNLocalDateTime(1_000_000_000);
        $date = $local->toDateTimeImmutable(new \DateTimeZone('+02:00'));
        self::assertSame('1970-01-01T00:00:01+02:00', $date->format('Y-m-d\TH:i:sP'));
    }

    public function testFromDateTimeImmutableKeepsTheWallClockAndDropsTheZone(): void
    {
        $date = new \DateTimeImmutable('1970-01-01T00:00:01.5', new \DateTimeZone('-05:00'));
        self::assertTrue((new DXNLocalDateTime(1_500_000_000))->equals(DXNLocalDateTime::fromDateTimeImmutable($date)));
    }

    public function testEqualsAndCompareTo(): void
    {
        self::assertTrue((new DXNLocalDateTime(5))->equals(new DXNLocalDateTime(5)));
        self::assertLessThan(0, (new DXNLocalDateTime(5))->compareTo(new DXNLocalDateTime(6)));
    }

    public function testDxnType(): void
    {
        self::assertSame('local-datetime', (new DXNLocalDateTime(0))->dxnType());
    }
}
