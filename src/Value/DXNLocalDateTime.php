<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `local-datetime` (`@local-datetime "2026-10-20T14:30:00"`) -- a
 * date and a time on the wall clock, with no offset, so no instant
 * (DXN.md §1.3; Jan, 2026-10-20). Held as `.dxnb` holds it: nanoseconds
 * since 1970-01-01T00:00:00 on the wall clock.
 *
 * `$localNanoseconds` is a PHP integer, 64 bits: it reaches from 1677
 * to 2262, and a value outside that is refused ({@see Nanos}).
 */
final class DXNLocalDateTime implements DXNValueInterface
{
    private const DXN_TYPE = 'local-datetime';

    public function __construct(public readonly int $localNanoseconds)
    {
    }

    /**
     * This wall-clock time in `$zone` (UTC when none is given), to the
     * microsecond -- a local date-time names no zone of its own, so the
     * caller picks one.
     *
     * @throws \RuntimeException when PHP cannot build it.
     */
    public function toDateTimeImmutable(?\DateTimeZone $zone = null): \DateTimeImmutable
    {
        [$date, $time] = Nanos::split($this->localNanoseconds);
        $formatted = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d.%06d',
            $date->year,
            $date->month,
            $date->day,
            $time->hour,
            $time->minute,
            $time->second,
            intdiv($time->nanosecond, 1000),
        );
        $built = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $formatted, $zone ?? new \DateTimeZone('UTC'));
        if ($built === false) {
            throw new \RuntimeException("failed to build DateTimeImmutable for \"{$formatted}\"");
        }
        return $built;
    }

    /**
     * `$date`'s wall clock, its zone dropped.
     *
     * @throws \InvalidArgumentException outside the range 64 bits hold.
     */
    public static function fromDateTimeImmutable(\DateTimeImmutable $date): self
    {
        return new self(Nanos::local(
            new DXNDate((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j')),
            new DXNTime((int) $date->format('G'), (int) $date->format('i'), (int) $date->format('s'), (int) $date->format('u') * 1000),
        ));
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return $this->localNanoseconds <=> $other->localNanoseconds;
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->localNanoseconds === $other->localNanoseconds;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
