<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `duration` (`@duration "P1Y2M"`) -- 7 independent, individually
 * optional fields, not reducible to one scalar. Seconds and their
 * fraction are one field, `nanoseconds` (Jan, 2026-10-20); a field's
 * sign is its own, `P-1DT2H`. No conversion helper:
 * PHP's `DateInterval` exists, but normalizes `P1W` into `days = 7`
 * internally, losing the weeks/days distinction DXN keeps
 * independent -- not a clean conversion target.
 */
final class DXNDuration implements DXNValueInterface
{
    private const DXN_TYPE = 'duration';

    public function __construct(
        public readonly ?int $years = null,
        public readonly ?int $months = null,
        public readonly ?int $weeks = null,
        public readonly ?int $days = null,
        public readonly ?int $hours = null,
        public readonly ?int $minutes = null,
        public readonly ?int $nanoseconds = null,
    ) {
    }

    public function compareTo(mixed $other): int
    {
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && $this->years === $other->years
            && $this->months === $other->months
            && $this->weeks === $other->weeks
            && $this->days === $other->days
            && $this->hours === $other->hours
            && $this->minutes === $other->minutes
            && $this->nanoseconds === $other->nanoseconds;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
