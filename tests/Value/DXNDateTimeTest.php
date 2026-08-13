<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNDateTime;
use PHPUnit\Framework\TestCase;

final class DXNDateTimeTest extends TestCase
{
    public function testWrapsEpochMicrosecondsAndOffset(): void
    {
        $dt = new DXNDateTime(1_700_000_000_123_456, 120);
        self::assertSame(1_700_000_000_123_456, $dt->epochMicroseconds);
        self::assertSame(120, $dt->offsetMinutes);
    }

    public function testToDateTimeImmutableAppliesTheOffset(): void
    {
        $dt = new DXNDateTime(1_700_000_000_000_000, -300);
        $date = $dt->toDateTimeImmutable();
        self::assertSame(-300 * 60, $date->getOffset());
    }

    public function testRoundTripsExactlyIncludingOffset(): void
    {
        $dt = new DXNDateTime(1_700_000_000_123_456, 90);
        $back = DXNDateTime::fromDateTimeImmutable($dt->toDateTimeImmutable(), 90);
        self::assertTrue($dt->equals($back));
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

    public function testDxnType(): void
    {
        self::assertSame('datetime', (new DXNDateTime(0, 0))->dxnType());
    }
}
