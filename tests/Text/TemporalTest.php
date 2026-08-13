<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\Text\Temporal;
use JOetjen\Dextrin\Value\DXNDuration;
use PHPUnit\Framework\TestCase;

final class TemporalTest extends TestCase
{
    public function testDateRoundTrips(): void
    {
        self::assertSame('2024-06-01', Temporal::formatDate(Temporal::parseDate('2024-06-01')));
    }

    public function testTimeRoundTripsWithAndWithoutFraction(): void
    {
        self::assertSame('13:45:30', Temporal::formatTime(Temporal::parseTime('13:45:30')));
        self::assertSame('13:45:30.123456', Temporal::formatTime(Temporal::parseTime('13:45:30.123456')));
    }

    public function testTimeFractionIsPaddedToMicroseconds(): void
    {
        $t = Temporal::parseTime('13:45:30.5');
        self::assertSame(500000, $t->microsecond);
    }

    public function testInstantRoundTripsIncludingPreEpoch(): void
    {
        self::assertSame('1990-01-01 00:00:00Z', Temporal::formatInstant(Temporal::parseInstant('1990-01-01 00:00:00Z')));

        $pre1970 = Temporal::parseInstant('1960-01-01 00:00:00.500000Z');
        self::assertSame('1960-01-01 00:00:00.500000Z', Temporal::formatInstant($pre1970));
    }

    public function testInstantRequiresTrailingZ(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Temporal::parseInstant('2024-06-01 00:00:00');
    }

    public function testOffsetDateTimeRoundTripsPositiveNegativeAndUtc(): void
    {
        self::assertSame('2024-06-01T13:45:30+02:00', Temporal::formatOffsetDateTime(Temporal::parseOffsetDateTime('2024-06-01T13:45:30+02:00')));
        self::assertSame('2024-06-01T13:45:30-05:30', Temporal::formatOffsetDateTime(Temporal::parseOffsetDateTime('2024-06-01T13:45:30-05:30')));
        self::assertSame('2024-06-01T13:45:30Z', Temporal::formatOffsetDateTime(Temporal::parseOffsetDateTime('2024-06-01T13:45:30Z')));
    }

    public function testOffsetDateTimeSameInstantDifferentOffsetHasDifferentEpoch(): void
    {
        $utc = Temporal::parseOffsetDateTime('2024-06-01T12:00:00Z');
        $plusTwo = Temporal::parseOffsetDateTime('2024-06-01T14:00:00+02:00');
        self::assertSame((string) $utc->epochMicroseconds, (string) $plusTwo->epochMicroseconds);
    }

    public function testDurationRoundTripsAllFieldKinds(): void
    {
        foreach (['P1Y2M3DT4H5M6.5S', 'P1W', 'P1Y', 'PT1H'] as $s) {
            self::assertSame($s, Temporal::formatDuration(Temporal::parseDuration($s)));
        }
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
}
