<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `time` (`@time "HH:MM:SS(.f{1,9})?"`) -- a bare time of day, ISO
 * 8601, to the nanosecond (DXN.md §1.3; Jan, 2026-10-20). No conversion
 * helper: PHP has no native "time of day" type to convert to/from
 * without a synthetic, misleading date component.
 */
final class DXNTime implements DXNValueInterface
{
    private const DXN_TYPE = 'time';
    private const NANOS_PER_SECOND = 1_000_000_000;

    public readonly int $hour;
    public readonly int $minute;
    public readonly int $second;
    public readonly int $nanosecond;

    /** @throws \InvalidArgumentException for a field out of its range. */
    public function __construct(int $hour, int $minute, int $second, int $nanosecond = 0)
    {
        if ($hour < 0 || $hour > 23) {
            throw new \InvalidArgumentException("hour out of range: {$hour}");
        }
        if ($minute < 0 || $minute > 59) {
            throw new \InvalidArgumentException("minute out of range: {$minute}");
        }
        if ($second < 0 || $second > 59) {
            throw new \InvalidArgumentException("second out of range: {$second}");
        }
        if ($nanosecond < 0 || $nanosecond > 999_999_999) {
            throw new \InvalidArgumentException("nanosecond out of range: {$nanosecond}");
        }
        $this->hour = $hour;
        $this->minute = $minute;
        $this->second = $second;
        $this->nanosecond = $nanosecond;
    }

    /**
     * The time `$nanos` after midnight -- what `.dxnb` holds (DXN.md §2.2).
     *
     * @throws \InvalidArgumentException for a count outside one day.
     */
    public static function fromNanosecondsSinceMidnight(int $nanos): self
    {
        if ($nanos < 0 || $nanos >= 86_400 * self::NANOS_PER_SECOND) {
            throw new \InvalidArgumentException("not a time of day: {$nanos} nanoseconds since midnight");
        }
        $seconds = intdiv($nanos, self::NANOS_PER_SECOND);
        return new self(intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60, $nanos % self::NANOS_PER_SECOND);
    }

    /** @return int this time's nanoseconds since midnight. */
    public function nanosecondsSinceMidnight(): int
    {
        return (($this->hour * 60 + $this->minute) * 60 + $this->second) * self::NANOS_PER_SECOND + $this->nanosecond;
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return $this->nanosecondsSinceMidnight() <=> $other->nanosecondsSinceMidnight();
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->nanosecondsSinceMidnight() === $other->nanosecondsSinceMidnight();
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
