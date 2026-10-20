<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNDuration;
use PHPUnit\Framework\TestCase;

final class DXNDurationTest extends TestCase
{
    public function testAllSevenFieldsAreIndependentAndOptional(): void
    {
        $d = new DXNDuration(years: 1, months: 2);
        self::assertSame(1, $d->years);
        self::assertSame(2, $d->months);
        self::assertNull($d->weeks);
        self::assertNull($d->days);
        self::assertNull($d->hours);
        self::assertNull($d->minutes);
        self::assertNull($d->nanoseconds);
    }

    public function testWeeksStaysIndependentFromDays(): void
    {
        // Unlike PHP's own DateInterval (which normalizes P1W into
        // days=7), DXNDuration keeps these as genuinely separate
        // fields -- that's exactly why no DateInterval conversion
        // helper exists on this class.
        $d = new DXNDuration(weeks: 1, days: 3);
        self::assertSame(1, $d->weeks);
        self::assertSame(3, $d->days);
    }

    public function testEqualsComparesAllSevenFields(): void
    {
        $a = new DXNDuration(years: 1, days: 3);
        $b = new DXNDuration(years: 1, days: 3);
        $c = new DXNDuration(years: 1, days: 4);
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function testDxnType(): void
    {
        self::assertSame('duration', (new DXNDuration())->dxnType());
    }
}
