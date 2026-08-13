<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNTimestamp;
use PHPUnit\Framework\TestCase;

final class DXNTimestampTest extends TestCase
{
    public function testWrapsEpochMicrosecondsAsInt(): void
    {
        self::assertSame(1_700_000_000_000_000, (new DXNTimestamp(1_700_000_000_000_000))->epochMicroseconds);
    }

    public function testRoundTripsThroughDateTimeImmutableExactly(): void
    {
        $ts = new DXNTimestamp(1_700_000_000_123_456);
        $back = DXNTimestamp::fromDateTimeImmutable($ts->toDateTimeImmutable());
        self::assertTrue($ts->equals($back), 'microsecond precision must round-trip exactly, not just to millisecond');
    }

    public function testRoundTripsNegativeEpochMicrosecondsWithSubSecondRemainder(): void
    {
        $ts = new DXNTimestamp(-500_123);
        $back = DXNTimestamp::fromDateTimeImmutable($ts->toDateTimeImmutable());
        self::assertTrue($ts->equals($back), 'pre-1970 instant must floor toward negative infinity correctly');
    }

    public function testWorksWithADXNIntegerOverflowValue(): void
    {
        $huge = DXNInteger::fromString('99999999999999999999999999');
        $ts = new DXNTimestamp($huge);
        self::assertSame($huge, $ts->epochMicroseconds);
    }

    public function testEqualsAndCompareTo(): void
    {
        self::assertTrue((new DXNTimestamp(100))->equals(new DXNTimestamp(100)));
        self::assertLessThan(0, (new DXNTimestamp(100))->compareTo(new DXNTimestamp(200)));
    }

    public function testDxnType(): void
    {
        self::assertSame('timestamp', (new DXNTimestamp(0))->dxnType());
    }
}
