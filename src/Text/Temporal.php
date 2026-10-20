<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNDuration;
use JOetjen\Dextrin\Value\DXNLocalDateTime;
use JOetjen\Dextrin\Value\DXNTime;
use JOetjen\Dextrin\Value\Nanos;

/**
 * DXN's dates and times as text (DXN.md §1.2, §1.5; Jan, 2026-10-20):
 * the ISO 8601 strings under `@date`, `@time`, `@datetime`,
 * `@local-datetime` and `@duration`, read and written in one place so
 * the parser, the printer and the canonical form never drift apart.
 *
 * Times keep up to nine fraction digits, nanoseconds; written, the
 * fraction loses its trailing zeros, and a datetime at offset zero is
 * written `Z`. A datetime's offset is `Z` or `±HH:MM`, and `-00:00`
 * ("offset unknown") is refused -- a value with no offset is a
 * local date-time. Every parse throws `\InvalidArgumentException` for
 * text that is not the form its tag names, which the parser turns into
 * a `syntax` error at the tag.
 */
final class Temporal
{
    private const DATE_RE = '/^(\d{4})-(\d{2})-(\d{2})$/';
    private const TIME_RE = '/^(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,9}))?$/';
    private const DATETIME_RE = '/^(\d{4}-\d{2}-\d{2})T([^Z+-]+)(Z|[+-]\d{2}:\d{2})$/';
    private const LOCAL_RE = '/^(\d{4}-\d{2}-\d{2})T(.+)$/';
    // A field's sign is its own, `P-1DT2H`; seconds take up to nine
    // fraction digits.
    private const DURATION_RE = '/^P(?:(-?\d+)Y)?(?:(-?\d+)M)?(?:(-?\d+)W)?(?:(-?\d+)D)?(?:T(?:(-?\d+)H)?(?:(-?\d+)M)?(?:(-?)(\d+)(?:\.(\d{1,9}))?S)?)?$/';

    private function __construct()
    {
    }

    // ---- reading text ----------------------------------------------------------

    /**
     * `YYYY-MM-DD`.
     *
     * @throws \InvalidArgumentException for any other text, or a date that is none.
     */
    public static function parseDate(string $text): DXNDate
    {
        if (!preg_match(self::DATE_RE, $text, $m)) {
            throw new \InvalidArgumentException("invalid date \"{$text}\"");
        }
        return new DXNDate((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /**
     * `HH:MM:SS`, then up to nine fraction digits.
     *
     * @throws \InvalidArgumentException for any other text, or a time that is none.
     */
    public static function parseTime(string $text): DXNTime
    {
        if (!preg_match(self::TIME_RE, $text, $m)) {
            throw new \InvalidArgumentException("invalid time \"{$text}\"");
        }
        $fraction = $m[4] ?? '';
        $nanosecond = $fraction === '' ? 0 : (int) str_pad($fraction, 9, '0');
        return new DXNTime((int) $m[1], (int) $m[2], (int) $m[3], $nanosecond);
    }

    /**
     * A date, `T`, a time, and an offset, `Z` or `±HH:MM`.
     *
     * @throws \InvalidArgumentException for any other text, `-00:00`, or an instant 64 bits cannot hold.
     */
    public static function parseDateTime(string $text): DXNDateTime
    {
        if (!preg_match(self::DATETIME_RE, $text, $m)) {
            throw new \InvalidArgumentException("invalid datetime \"{$text}\"");
        }
        $offset = self::offsetMinutes($m[3], $text);
        $local = Nanos::local(self::parseDate($m[1]), self::parseTime($m[2]));
        return new DXNDateTime(Nanos::checked($local - $offset * 60 * Nanos::PER_SECOND, "datetime \"{$text}\""), $offset);
    }

    /**
     * A date, `T` and a time, no offset.
     *
     * @throws \InvalidArgumentException for any other text, or a value 64 bits cannot hold.
     */
    public static function parseLocalDateTime(string $text): DXNLocalDateTime
    {
        if (!preg_match(self::LOCAL_RE, $text, $m)) {
            throw new \InvalidArgumentException("invalid local-datetime \"{$text}\"");
        }
        return new DXNLocalDateTime(Nanos::local(self::parseDate($m[1]), self::parseTime($m[2])));
    }

    /** `Z` is zero; `-00:00`, an unknown offset, is refused. */
    private static function offsetMinutes(string $offset, string $text): int
    {
        if ($offset === 'Z') {
            return 0;
        }
        $hours = (int) substr($offset, 1, 2);
        $minutes = (int) substr($offset, 4, 2);
        if ($offset === '-00:00' || $hours > 23 || $minutes > 59) {
            throw new \InvalidArgumentException("invalid datetime \"{$text}\"");
        }
        return ($offset[0] === '-' ? -1 : 1) * ($hours * 60 + $minutes);
    }

    /**
     * `P...`, ISO 8601, each field's sign its own, the seconds with up to
     * nine fraction digits.
     *
     * @throws \InvalidArgumentException for any other text, or no field at all.
     */
    public static function parseDuration(string $text): DXNDuration
    {
        if ($text === 'P' || str_ends_with($text, 'T') || !preg_match(self::DURATION_RE, $text, $m)) {
            throw new \InvalidArgumentException("invalid duration \"{$text}\"");
        }

        $nanoseconds = null;
        $seconds = $m[8] ?? '';
        if ($seconds !== '') {
            $fraction = $m[9] ?? '';
            $nanoseconds = Nanos::checked(
                (int) $seconds * Nanos::PER_SECOND + ($fraction === '' ? 0 : (int) str_pad($fraction, 9, '0')),
                "duration \"{$text}\"",
            );
            if (($m[7] ?? '') === '-') {
                $nanoseconds = -$nanoseconds;
            }
        }

        return new DXNDuration(
            years: self::intOrNull($m[1] ?? ''),
            months: self::intOrNull($m[2] ?? ''),
            weeks: self::intOrNull($m[3] ?? ''),
            days: self::intOrNull($m[4] ?? ''),
            hours: self::intOrNull($m[5] ?? ''),
            minutes: self::intOrNull($m[6] ?? ''),
            nanoseconds: $nanoseconds,
        );
    }

    private static function intOrNull(string $s): ?int
    {
        return $s === '' ? null : (int) $s;
    }

    // ---- writing text ------------------------------------------------------------

    public static function formatDate(DXNDate $date): string
    {
        return sprintf('%04d-%02d-%02d', $date->year, $date->month, $date->day);
    }

    /** `HH:MM:SS`, then the fraction with trailing zeros removed, or nothing when it is zero (DXN.md §1.5). */
    public static function formatTime(DXNTime $time): string
    {
        return sprintf('%02d:%02d:%02d', $time->hour, $time->minute, $time->second) . self::fraction($time->nanosecond);
    }

    /** The wall clock at its offset, then `Z` for zero or `±HH:MM`. */
    public static function formatDateTime(DXNDateTime $dt): string
    {
        $local = Nanos::checked($dt->epochNanoseconds + $dt->offsetMinutes * 60 * Nanos::PER_SECOND, 'a datetime at its offset');
        return self::formatLocalNanos($local) . self::offsetName($dt->offsetMinutes);
    }

    /** `YYYY-MM-DDTHH:MM:SS` and the fraction. */
    public static function formatLocalDateTime(DXNLocalDateTime $local): string
    {
        return self::formatLocalNanos($local->localNanoseconds);
    }

    private static function formatLocalNanos(int $local): string
    {
        [$date, $time] = Nanos::split($local);
        return self::formatDate($date) . 'T' . self::formatTime($time);
    }

    /** An offset in minutes as a datetime writes it: `Z`, or `±HH:MM`. */
    public static function offsetName(int $minutes): string
    {
        return $minutes === 0 ? 'Z' : Nanos::zoneName($minutes);
    }

    /** `P...`: the fields in DXN.md §2.3's order, absent ones left out, `PT0S` when none. */
    public static function formatDuration(DXNDuration $d): string
    {
        $date = self::part($d->years, 'Y') . self::part($d->months, 'M') . self::part($d->weeks, 'W') . self::part($d->days, 'D');
        $time = self::part($d->hours, 'H') . self::part($d->minutes, 'M');
        if ($d->nanoseconds !== null) {
            $abs = abs($d->nanoseconds);
            $time .= ($d->nanoseconds < 0 ? '-' : '') . intdiv($abs, Nanos::PER_SECOND) . self::fraction($abs % Nanos::PER_SECOND) . 'S';
        }
        return match (true) {
            $date === '' && $time === '' => 'PT0S',
            $time === '' => "P{$date}",
            default => "P{$date}T{$time}",
        };
    }

    private static function part(?int $n, string $unit): string
    {
        return $n === null ? '' : $n . $unit;
    }

    /** `.` and the nanoseconds as nine digits less their trailing zeros, or nothing for none. */
    private static function fraction(int $nanos): string
    {
        return $nanos === 0 ? '' : '.' . rtrim(str_pad((string) $nanos, 9, '0', STR_PAD_LEFT), '0');
    }

    /**
     * A date, time, datetime, local date-time or duration as its tag and
     * ISO 8601 text, `['date', '2026-10-20']`, or null for any other
     * value -- what the printer and the canonical form both write. A
     * native `\DateTimeInterface` is the datetime it always means.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function tagged(mixed $value): ?array
    {
        return match (true) {
            $value instanceof DXNDate => ['date', self::formatDate($value)],
            $value instanceof DXNTime => ['time', self::formatTime($value)],
            $value instanceof DXNDateTime => ['datetime', self::formatDateTime($value)],
            $value instanceof \DateTimeInterface => ['datetime', self::formatDateTime(DXNDateTime::fromNative($value))],
            $value instanceof DXNLocalDateTime => ['local-datetime', self::formatLocalDateTime($value)],
            $value instanceof DXNDuration => ['duration', self::formatDuration($value)],
            default => null,
        };
    }
}
