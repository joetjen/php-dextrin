<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/** DXN `date` (`@date "YYYY-MM-DD"`) -- a bare calendar date, ISO 8601. */
final class DXNDate implements DXNValueInterface
{
    private const DXN_TYPE = 'date';

    public readonly int $year;
    public readonly int $month;
    public readonly int $day;

    public function __construct(int $year, int $month, int $day)
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException("month out of range: {$month}");
        }
        if ($day < 1 || $day > self::daysInMonth($year, $month)) {
            throw new \InvalidArgumentException("invalid date: {$year}-{$month}-{$day}");
        }
        $this->year = $year;
        $this->month = $month;
        $this->day = $day;
    }

    /**
     * Hand-rolled rather than `checkdate()`, which only supports
     * years 1..32767 -- DXN's own date range isn't bounded that way,
     * and `checkdate`'s year clamping would silently misjudge leap
     * years outside that range.
     */
    private static function daysInMonth(int $year, int $month): int
    {
        static $days = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if ($month === 2 && self::isLeapYear($year)) {
            return 29;
        }
        return $days[$month - 1];
    }

    private static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    /**
     * A `DateTimeImmutable` at UTC midnight -- a documented, one-way
     * -safe convention for a bare-date type PHP has no exact native
     * equivalent for (`DateTimeImmutable` always carries a time and a
     * zone; midnight UTC is the least-surprising synthetic choice).
     */
    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-n-j',
            "{$this->year}-{$this->month}-{$this->day}",
            new \DateTimeZone('UTC'),
        );
        if ($date === false) {
            throw new \RuntimeException("failed to build DateTimeImmutable for {$this->year}-{$this->month}-{$this->day}");
        }
        return $date;
    }

    /** Reads the UTC Y/M/D of `$date`, discarding time-of-day and any non-UTC offset. */
    public static function fromDateTimeImmutable(\DateTimeImmutable $date): self
    {
        $utc = $date->setTimezone(new \DateTimeZone('UTC'));
        return new self((int) $utc->format('Y'), (int) $utc->format('n'), (int) $utc->format('j'));
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return [$this->year, $this->month, $this->day] <=> [$other->year, $other->month, $other->day];
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && $this->year === $other->year
            && $this->month === $other->month
            && $this->day === $other->day;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
