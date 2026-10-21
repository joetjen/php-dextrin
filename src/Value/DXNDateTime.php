<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `datetime` (`@datetime "2026-10-20T14:30:00Z"`) -- an instant with
 * its offset, `Z` for UTC (DXN.md §1.3). DXN 0.2's `timestamp`, a UTC
 * instant, is one of these at offset zero (Jan, 2026-10-20). Mirrors
 * `.dxnb`'s wire shape directly: the instant as nanoseconds since the
 * epoch, and the offset it was written with, in minutes.
 *
 * `$epochNanoseconds` is a PHP integer, 64 bits: it reaches from 1677
 * to 2262, and an instant outside that is refused ({@see Nanos}).
 * `DateTimeImmutable` stops at the microsecond, so
 * {@see self::toDateTimeImmutable()} drops what is finer, and
 * {@see self::fromDateTimeImmutable()} is exact.
 */
final class DXNDateTime implements DXNValueInterface
{
    private const DXN_TYPE = 'datetime';

    public readonly int $epochNanoseconds;
    public readonly int $offsetMinutes;

    /** @throws \InvalidArgumentException for an offset of a day or more. */
    public function __construct(int $epochNanoseconds, int $offsetMinutes)
    {
        if (abs($offsetMinutes) >= 1440) {
            throw new \InvalidArgumentException("offset out of range: {$offsetMinutes} minutes");
        }
        $this->epochNanoseconds = $epochNanoseconds;
        $this->offsetMinutes = $offsetMinutes;
    }

    /**
     * The instant at its offset, to the microsecond.
     *
     * @throws \RuntimeException when PHP cannot build it.
     */
    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        [$seconds, $micros] = Nanos::secondsAndMicros($this->epochNanoseconds);
        $utc = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micros), new \DateTimeZone('UTC'));
        if ($utc === false) {
            throw new \RuntimeException("failed to build DateTimeImmutable for epoch nanoseconds {$this->epochNanoseconds}");
        }
        return $utc->setTimezone(new \DateTimeZone(Nanos::zoneName($this->offsetMinutes)));
    }

    /**
     * `$offsetMinutes` may be supplied explicitly -- `DateTimeImmutable`
     * can't infer "the offset the caller means" any more than a plain
     * instant can; it only knows the offset its own `DateTimeZone`
     * carries, which this defaults to when not given.
     *
     * @throws \InvalidArgumentException outside the range 64 bits hold.
     */
    public static function fromDateTimeImmutable(\DateTimeImmutable $date, ?int $offsetMinutes = null): self
    {
        return new self(Nanos::ofNative($date), $offsetMinutes ?? intdiv($date->getOffset(), 60));
    }

    /**
     * A native date, as the `datetime` it always means (DXNS.md §7):
     * its instant, with the offset its own zone carries at that instant.
     * A host value means the same DXN type with or without a schema, and
     * a native date always has a zone, which `datetime` keeps.
     *
     * @throws \InvalidArgumentException outside the range 64 bits hold.
     */
    public static function fromNative(\DateTimeInterface $date): self
    {
        return self::fromDateTimeImmutable(\DateTimeImmutable::createFromInterface($date));
    }

    /** Ordered by instant, whatever the offsets. */
    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return $this->epochNanoseconds <=> $other->epochNanoseconds;
        }
        return Equality::dxnCompare($this, $other);
    }

    /** The same instant at the same offset: one written at another offset is another value. */
    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && $this->epochNanoseconds === $other->epochNanoseconds
            && $this->offsetMinutes === $other->offsetMinutes;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
