<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `time` (`~T[HH:MM:SS(.ffffff)?]`) -- a bare time of day, ISO
 * 8601. No conversion helper: PHP has no native "time of day" type to
 * convert to/from without a synthetic, misleading date component.
 */
final class DXNTime implements DXNValueInterface
{
    private const DXN_TYPE = 'time';

    public readonly int $hour;
    public readonly int $minute;
    public readonly int $second;
    public readonly int $microsecond;

    public function __construct(int $hour, int $minute, int $second, int $microsecond = 0)
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
        if ($microsecond < 0 || $microsecond > 999_999) {
            throw new \InvalidArgumentException("microsecond out of range: {$microsecond}");
        }
        $this->hour = $hour;
        $this->minute = $minute;
        $this->second = $second;
        $this->microsecond = $microsecond;
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return [$this->hour, $this->minute, $this->second, $this->microsecond]
                <=> [$other->hour, $other->minute, $other->second, $other->microsecond];
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && $this->hour === $other->hour
            && $this->minute === $other->minute
            && $this->second === $other->second
            && $this->microsecond === $other->microsecond;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
