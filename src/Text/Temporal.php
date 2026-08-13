<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNDuration;
use JOetjen\Dextrin\Value\DXNTime;
use JOetjen\Dextrin\Value\DXNTimestamp;

/**
 * ISO 8601 parsing/formatting shared by the `.dxn` text parser and
 * printer for `date`/`time`/`timestamp`/`datetime`/`duration` -- one
 * implementation so encode/decode never drift apart. Builds instants
 * via `DateTimeImmutable` (constructing the wall-clock value directly
 * in the relevant `DateTimeZone`, then reading back the UTC epoch)
 * rather than hand-rolling epoch arithmetic -- PHP's own offset
 * handling is already correct, unlike JS, which needed a hand-written
 * floor-division helper to get pre-epoch instants right.
 */
final class Temporal
{
    private const DATE_RE = '/^(\d{4})-(\d{2})-(\d{2})$/';
    private const TIME_RE = '/^(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?$/';
    private const OFFSET_RE = '/^([+-])(\d{2}):(\d{2})$/';
    private const DURATION_RE = '/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/';

    private function __construct()
    {
    }

    public static function parseDate(string $text): DXNDate
    {
        if (!preg_match(self::DATE_RE, $text, $m)) {
            throw new \InvalidArgumentException("invalid date \"{$text}\"");
        }
        return new DXNDate((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    public static function formatDate(DXNDate $date): string
    {
        return sprintf('%04d-%02d-%02d', $date->year, $date->month, $date->day);
    }

    public static function parseTime(string $text): DXNTime
    {
        if (!preg_match(self::TIME_RE, $text, $m)) {
            throw new \InvalidArgumentException("invalid time \"{$text}\"");
        }
        $fraction = $m[4] ?? '';
        $microsecond = $fraction === '' ? 0 : (int) str_pad($fraction, 6, '0');
        return new DXNTime((int) $m[1], (int) $m[2], (int) $m[3], $microsecond);
    }

    public static function formatTime(DXNTime $time): string
    {
        $base = sprintf('%02d:%02d:%02d', $time->hour, $time->minute, $time->second);
        if ($time->microsecond === 0) {
            return $base;
        }
        return $base . '.' . str_pad((string) $time->microsecond, 6, '0', STR_PAD_LEFT);
    }

    /** `~U[YYYY-MM-DD HH:MM:SSZ]` -- date and time separated by one literal space, `Z` mandatory. */
    public static function parseInstant(string $inner): DXNTimestamp
    {
        $spaceAt = strpos($inner, ' ');
        if ($spaceAt === false) {
            throw new \InvalidArgumentException("invalid timestamp \"{$inner}\"");
        }
        $datePart = substr($inner, 0, $spaceAt);
        $timePart = substr($inner, $spaceAt + 1);
        if (!str_ends_with($timePart, 'Z')) {
            throw new \InvalidArgumentException("timestamp must end in 'Z': \"{$inner}\"");
        }
        $timePart = substr($timePart, 0, -1);

        $date = self::parseDate($datePart);
        $time = self::parseTime($timePart);
        return DXNTimestamp::fromDateTimeImmutable(self::buildDateTimeImmutable($date, $time, new \DateTimeZone('UTC')));
    }

    public static function formatInstant(DXNTimestamp $ts): string
    {
        $d = $ts->toDateTimeImmutable();
        return self::formatDate(DXNDate::fromDateTimeImmutable($d)) . ' ' . self::formatTime(self::extractTime($d)) . 'Z';
    }

    /** `YYYY-MM-DDTHH:MM:SS(.ffffff)?(Z|±HH:MM)` -- RFC 3339 separator, offset required (`Z` accepted defensively). */
    public static function parseOffsetDateTime(string $text): DXNDateTime
    {
        $tAt = strpos($text, 'T');
        if ($tAt === false) {
            throw new \InvalidArgumentException("invalid datetime \"{$text}\"");
        }
        $datePart = substr($text, 0, $tAt);
        $rest = substr($text, $tAt + 1);

        if (str_ends_with($rest, 'Z')) {
            $timePart = substr($rest, 0, -1);
            $offsetMinutes = 0;
        } else {
            $offsetCandidate = substr($rest, -6);
            if (!preg_match(self::OFFSET_RE, $offsetCandidate, $om)) {
                throw new \InvalidArgumentException("invalid datetime offset in \"{$text}\"");
            }
            $timePart = substr($rest, 0, -6);
            $sign = $om[1] === '-' ? -1 : 1;
            $offsetMinutes = $sign * ((int) $om[2] * 60 + (int) $om[3]);
        }

        $date = self::parseDate($datePart);
        $time = self::parseTime($timePart);
        $local = self::buildDateTimeImmutable($date, $time, self::offsetTimeZone($offsetMinutes));
        return DXNDateTime::fromDateTimeImmutable($local, $offsetMinutes);
    }

    public static function formatOffsetDateTime(DXNDateTime $dt): string
    {
        $d = $dt->toDateTimeImmutable();
        $date = self::formatDate(DXNDate::fromDateTimeImmutable($d));
        $time = self::formatTime(self::extractTime($d));
        return $date . 'T' . $time . self::formatOffsetMinutes($dt->offsetMinutes);
    }

    private static function formatOffsetMinutes(int $offsetMinutes): string
    {
        if ($offsetMinutes === 0) {
            return 'Z';
        }
        $sign = $offsetMinutes < 0 ? '-' : '+';
        $abs = abs($offsetMinutes);
        return sprintf('%s%02d:%02d', $sign, intdiv($abs, 60), $abs % 60);
    }

    public static function parseDuration(string $text): DXNDuration
    {
        if ($text === 'P' || $text === 'PT' || !preg_match(self::DURATION_RE, $text, $m)) {
            throw new \InvalidArgumentException("invalid duration \"{$text}\"");
        }

        $microseconds = null;
        $secondsRaw = $m[7] ?? '';
        if ($secondsRaw !== '') {
            $parts = explode('.', $secondsRaw, 2);
            $whole = (int) $parts[0];
            $fracStr = $parts[1] ?? '';
            $frac = (int) str_pad(substr($fracStr, 0, 6), 6, '0');
            $microseconds = $whole * 1_000_000 + $frac;
        }

        return new DXNDuration(
            years: self::intOrNull($m[1] ?? ''),
            months: self::intOrNull($m[2] ?? ''),
            weeks: self::intOrNull($m[3] ?? ''),
            days: self::intOrNull($m[4] ?? ''),
            hours: self::intOrNull($m[5] ?? ''),
            minutes: self::intOrNull($m[6] ?? ''),
            microseconds: $microseconds,
        );
    }

    public static function formatDuration(DXNDuration $d): string
    {
        $out = 'P';
        if ($d->years !== null) {
            $out .= "{$d->years}Y";
        }
        if ($d->months !== null) {
            $out .= "{$d->months}M";
        }
        if ($d->weeks !== null) {
            $out .= "{$d->weeks}W";
        }
        if ($d->days !== null) {
            $out .= "{$d->days}D";
        }

        $time = '';
        if ($d->hours !== null) {
            $time .= "{$d->hours}H";
        }
        if ($d->minutes !== null) {
            $time .= "{$d->minutes}M";
        }
        if ($d->microseconds !== null) {
            $time .= self::formatSeconds($d->microseconds) . 'S';
        }
        if ($time !== '') {
            $out .= "T{$time}";
        }

        return $out === 'P' ? 'PT0S' : $out;
    }

    private static function formatSeconds(int $microseconds): string
    {
        $whole = intdiv($microseconds, 1_000_000);
        $frac = abs($microseconds % 1_000_000);
        if ($frac === 0) {
            return (string) $whole;
        }
        $fracStr = rtrim(str_pad((string) $frac, 6, '0', STR_PAD_LEFT), '0');
        return "{$whole}.{$fracStr}";
    }

    private static function intOrNull(string $s): ?int
    {
        return $s === '' ? null : (int) $s;
    }

    private static function buildDateTimeImmutable(DXNDate $date, DXNTime $time, \DateTimeZone $tz): \DateTimeImmutable
    {
        $formatted = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d.%06d',
            $date->year,
            $date->month,
            $date->day,
            $time->hour,
            $time->minute,
            $time->second,
            $time->microsecond,
        );
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $formatted, $tz);
        if ($dt === false) {
            throw new \RuntimeException("failed to build DateTimeImmutable for \"{$formatted}\"");
        }
        return $dt;
    }

    private static function extractTime(\DateTimeImmutable $d): DXNTime
    {
        return new DXNTime((int) $d->format('G'), (int) $d->format('i'), (int) $d->format('s'), (int) $d->format('u'));
    }

    private static function offsetTimeZone(int $offsetMinutes): \DateTimeZone
    {
        if ($offsetMinutes === 0) {
            return new \DateTimeZone('UTC');
        }
        $sign = $offsetMinutes < 0 ? '-' : '+';
        $abs = abs($offsetMinutes);
        return new \DateTimeZone(sprintf('%s%02d:%02d', $sign, intdiv($abs, 60), $abs % 60));
    }
}
