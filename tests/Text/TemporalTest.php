<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\Text\Temporal;
use JOetjen\Dextrin\Value\DXNDuration;
use PHPUnit\Framework\TestCase;

/**
 * The ISO 8601 strings under `@date`, `@time`, `@datetime`,
 * `@local-datetime` and `@duration` (DXN.md §1.2, §1.5).
 */
final class TemporalTest extends TestCase
{
    public function testDateRoundTrips(): void
    {
        self::assertSame('2024-06-01', Temporal::formatDate(Temporal::parseDate('2024-06-01')));
    }

    public function testADateIsFourTwoAndTwoDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Temporal::parseDate('2024-6-1');
    }

    public function testTimeRoundTripsWithAndWithoutFractionItsTrailingZerosDropped(): void
    {
        self::assertSame('13:45:30', Temporal::formatTime(Temporal::parseTime('13:45:30')));
        self::assertSame('13:45:30.123456789', Temporal::formatTime(Temporal::parseTime('13:45:30.123456789')));
        self::assertSame('13:45:30.5', Temporal::formatTime(Temporal::parseTime('13:45:30.500000')));
        self::assertSame('13:45:30', Temporal::formatTime(Temporal::parseTime('13:45:30.000')));
    }

    public function testTimeFractionIsPaddedToNanoseconds(): void
    {
        self::assertSame(500_000_000, Temporal::parseTime('13:45:30.5')->nanosecond);
    }

    public function testTimeTakesNoMoreThanNineFractionDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Temporal::parseTime('13:45:30.1234567891');
    }

    public function testDateTimeRoundTripsPositiveNegativeAndUtcIncludingPreEpoch(): void
    {
        foreach ([
            '2024-06-01T13:45:30+02:00',
            '2024-06-01T13:45:30-05:30',
            '2024-06-01T13:45:30Z',
            '1960-01-01T00:00:00.5Z',
        ] as $s) {
            self::assertSame($s, Temporal::formatDateTime(Temporal::parseDateTime($s)));
        }
    }

    public function testTheSameInstantAtAnotherOffsetHasTheSameEpoch(): void
    {
        $utc = Temporal::parseDateTime('2024-06-01T12:00:00Z');
        $plusTwo = Temporal::parseDateTime('2024-06-01T14:00:00+02:00');
        self::assertSame($utc->epochNanoseconds, $plusTwo->epochNanoseconds);
        self::assertSame(120, $plusTwo->offsetMinutes);
    }

    public function testAnUnknownOffsetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Temporal::parseDateTime('2024-06-01T12:00:00-00:00');
    }

    public function testALocalDateTimeRoundTripsAndHasNoOffset(): void
    {
        self::assertSame('1969-12-31T23:59:59.999999999', Temporal::formatLocalDateTime(Temporal::parseLocalDateTime('1969-12-31T23:59:59.999999999')));
        self::assertSame(-1, Temporal::parseLocalDateTime('1969-12-31T23:59:59.999999999')->localNanoseconds);
    }

    public function testDurationRoundTripsAllFieldKinds(): void
    {
        foreach (['P1Y2M3DT4H5M6.5S', 'P1W', 'P1Y', 'PT1H', 'PT0.000000001S', 'P-1D', 'PT-1.5S'] as $s) {
            self::assertSame($s, Temporal::formatDuration(Temporal::parseDuration($s)));
        }
    }

    public function testDurationSecondsAreNanoseconds(): void
    {
        self::assertSame(1_500_000_000, Temporal::parseDuration('PT1.5S')->nanoseconds);
        self::assertSame('PT1S', Temporal::formatDuration(Temporal::parseDuration('PT1.0S')));
    }

    public function testDurationWeeksStaysIndependentOfDays(): void
    {
        $d = Temporal::parseDuration('P1W3D');
        self::assertSame(1, $d->weeks);
        self::assertSame(3, $d->days);
    }

    public function testEmptyDurationFormatsAsZeroSeconds(): void
    {
        self::assertSame('PT0S', Temporal::formatDuration(new DXNDuration()));
    }

    public function testDurationRejectsBareP(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Temporal::parseDuration('P');
    }

    public function testDurationRejectsBarePT(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Temporal::parseDuration('PT');
    }

    public function testDurationRejectsALeadingSign(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Temporal::parseDuration('-P1D');
    }
}
