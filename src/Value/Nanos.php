<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * The nanosecond arithmetic {@see DXNTime}, {@see DXNDateTime} and
 * {@see DXNLocalDateTime} share (DXN.md §1.3, §2.2; Jan, 2026-10-20):
 * days since 1970-01-01 and back, a date and a time of day as
 * nanoseconds since 1970-01-01T00:00:00 on the wall clock, and back.
 *
 * **64 bits are the range.** PHP's integers are 64-bit, so nanoseconds
 * since the epoch reach from 1677-09-21T00:12:43.145224192 to
 * 2262-04-11T23:47:16.854775807 -- the reference's own integers are
 * unbounded. An instant outside that range is refused
 * (`\InvalidArgumentException`), never wrapped or rounded: PHP turns an
 * integer that overflows into a float, which {@see self::checked()}
 * catches.
 *
 * Dates are converted with Howard Hinnant's civil-calendar algorithms,
 * pure integer arithmetic on the proleptic Gregorian calendar, rather
 * than through `DateTimeImmutable`, so no year is out of their reach.
 *
 * @internal
 */
final class Nanos
{
    public const PER_SECOND = 1_000_000_000;
    public const PER_DAY = 86_400 * self::PER_SECOND;

    private function __construct()
    {
    }

    /**
     * `$n` when the arithmetic that made it stayed an integer.
     *
     * @throws \InvalidArgumentException when it overflowed into a float.
     */
    public static function checked(int|float $n, string $what): int
    {
        if (!is_int($n)) {
            throw new \InvalidArgumentException("{$what} is out of range: nanoseconds since 1970 fit 1677 to 2262 in 64 bits");
        }
        return $n;
    }

    /** `$a` divided by `$b`, rounded toward negative infinity. */
    public static function floorDiv(int $a, int $b): int
    {
        $q = intdiv($a, $b);
        return ($a % $b !== 0 && ($a < 0) !== ($b < 0)) ? $q - 1 : $q;
    }

    /** The days from 1970-01-01 to `$date`, negative before it. */
    public static function epochDays(DXNDate $date): int
    {
        $y = $date->month <= 2 ? $date->year - 1 : $date->year;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yearOfEra = $y - $era * 400;
        $dayOfYear = intdiv(153 * ($date->month + ($date->month > 2 ? -3 : 9)) + 2, 5) + $date->day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;
        return $era * 146_097 + $dayOfEra - 719_468;
    }

    /** The date `$days` after 1970-01-01. */
    public static function dateFromEpochDays(int $days): DXNDate
    {
        $z = $days + 719_468;
        $era = intdiv($z >= 0 ? $z : $z - 146_096, 146_097);
        $dayOfEra = $z - $era * 146_097;
        $yearOfEra = intdiv($dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36_524) - intdiv($dayOfEra, 146_096), 365);
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $mp = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp < 10 ? $mp + 3 : $mp - 9;
        return new DXNDate($yearOfEra + $era * 400 + ($month <= 2 ? 1 : 0), $month, $day);
    }

    /**
     * `$date` at `$time` on the wall clock, as nanoseconds since
     * 1970-01-01T00:00:00.
     *
     * @throws \InvalidArgumentException outside 64 bits' range.
     */
    public static function local(DXNDate $date, DXNTime $time): int
    {
        return self::checked(self::epochDays($date) * self::PER_DAY + $time->nanosecondsSinceMidnight(), 'a date and time');
    }

    /**
     * The date and the time of day `$local` nanoseconds after
     * 1970-01-01T00:00:00 on the wall clock.
     *
     * @return array{0: DXNDate, 1: DXNTime}
     */
    public static function split(int $local): array
    {
        $days = self::floorDiv($local, self::PER_DAY);
        return [self::dateFromEpochDays($days), DXNTime::fromNanosecondsSinceMidnight($local - $days * self::PER_DAY)];
    }

    /**
     * `$nanos` as whole seconds since the epoch and the microseconds past
     * them -- what `DateTimeImmutable` holds; finer than that is dropped,
     * toward negative infinity.
     *
     * @return array{0: int, 1: int}
     */
    public static function secondsAndMicros(int $nanos): array
    {
        $seconds = self::floorDiv($nanos, self::PER_SECOND);
        return [$seconds, intdiv($nanos - $seconds * self::PER_SECOND, 1000)];
    }

    /**
     * A `DateTimeImmutable`'s instant as nanoseconds since the epoch.
     *
     * @throws \InvalidArgumentException outside 64 bits' range.
     */
    public static function ofNative(\DateTimeInterface $date): int
    {
        return self::checked($date->getTimestamp() * self::PER_SECOND + (int) $date->format('u') * 1000, 'a native date');
    }

    /** `±HH:MM` for an offset of `$minutes`, `+00:00` for zero -- what `DateTimeZone` takes. */
    public static function zoneName(int $minutes): string
    {
        $abs = abs($minutes);
        return sprintf('%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv($abs, 60), $abs % 60);
    }
}
