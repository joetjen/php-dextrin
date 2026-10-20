<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNDateTime;
use PHPUnit\Framework\TestCase;

/**
 * DXN `datetime`: an instant with its offset, `Z` for UTC -- DXN 0.2's
 * `timestamp` is one of these (Jan, 2026-10-20).
 */
final class DXNDateTimeTest extends TestCase
{
    public function testWrapsEpochNanosecondsAndOffset(): void
    {
        $dt = new DXNDateTime(1_700_000_000_123_456_789, 120);
        self::assertSame(1_700_000_000_123_456_789, $dt->epochNanoseconds);
        self::assertSame(120, $dt->offsetMinutes);
    }

    public function testRefusesAnOffsetOfADayOrMore(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNDateTime(0, 1440);
    }

    public function testToDateTimeImmutableAppliesTheOffset(): void
    {
        $dt = new DXNDateTime(1_700_000_000_000_000_000, -300);
        $date = $dt->toDateTimeImmutable();
        self::assertSame(-300 * 60, $date->getOffset());
    }

    public function testRoundTripsExactlyToTheMicrosecondIncludingOffset(): void
    {
        $dt = new DXNDateTime(1_700_000_000_123_456_000, 90);
        $back = DXNDateTime::fromDateTimeImmutable($dt->toDateTimeImmutable(), 90);
        self::assertTrue($dt->equals($back));
    }

    public function testANativeDateKeepsTheMicrosecondAndDropsWhatIsFiner(): void
    {
        $dt = new DXNDateTime(1_700_000_000_123_456_789, 0);
        self::assertSame('2023-11-14 22:13:20.123456', $dt->toDateTimeImmutable()->format('Y-m-d H:i:s.u'));
    }

    public function testRoundTripsAPreEpochInstantWithASubSecondRemainder(): void
    {
        $dt = new DXNDateTime(-500_123_000, 0);
        $back = DXNDateTime::fromDateTimeImmutable($dt->toDateTimeImmutable());
        self::assertTrue($dt->equals($back), 'a pre-1970 instant must floor toward negative infinity');
    }

    public function testFromDateTimeImmutableDefaultsOffsetFromTheZone(): void
    {
        $date = new \DateTimeImmutable('2024-06-01T12:00:00', new \DateTimeZone('+02:00'));
        $dt = DXNDateTime::fromDateTimeImmutable($date);
        self::assertSame(120, $dt->offsetMinutes);
    }

    public function testEqualsRequiresSameOffsetToo(): void
    {
        $a = new DXNDateTime(100, 60);
        $b = new DXNDateTime(100, 0);
        self::assertFalse($a->equals($b));
    }

    public function testComparesByInstant(): void
    {
        self::assertLessThan(0, (new DXNDateTime(100, 0))->compareTo(new DXNDateTime(200, 0)));
    }

    public function testDxnType(): void
    {
        self::assertSame('datetime', (new DXNDateTime(0, 0))->dxnType());
    }
}
