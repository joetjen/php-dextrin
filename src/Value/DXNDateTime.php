<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `datetime` (`@datetime "..."`) -- an offset-aware instant,
 * non-UTC. Mirrors `.dxnb`'s wire shape directly: an instant plus an
 * informational offset. Same exact (not lossy) microsecond-precision
 * conversion as {@see DXNTimestamp}.
 */
final class DXNDateTime implements DXNValueInterface
{
    private const DXN_TYPE = 'datetime';

    public readonly int|DXNInteger $epochMicroseconds;
    public readonly int $offsetMinutes;

    public function __construct(int|DXNInteger $epochMicroseconds, int $offsetMinutes)
    {
        $this->epochMicroseconds = $epochMicroseconds;
        $this->offsetMinutes = $offsetMinutes;
    }

    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        $utc = (new DXNTimestamp($this->epochMicroseconds))->toDateTimeImmutable();
        $sign = $this->offsetMinutes < 0 ? '-' : '+';
        $absMinutes = abs($this->offsetMinutes);
        $offset = sprintf('%s%02d:%02d', $sign, intdiv($absMinutes, 60), $absMinutes % 60);
        return $utc->setTimezone(new \DateTimeZone($offset));
    }

    /**
     * `$offsetMinutes` must be supplied explicitly -- `DateTimeImmutable`
     * can't infer "the offset the caller means" any more than a plain
     * instant can; it only knows the offset its own `DateTimeZone`
     * carries, which this defaults to when not given.
     */
    public static function fromDateTimeImmutable(\DateTimeImmutable $date, ?int $offsetMinutes = null): self
    {
        $timestamp = DXNTimestamp::fromDateTimeImmutable($date);
        return new self($timestamp->epochMicroseconds, $offsetMinutes ?? intdiv($date->getOffset(), 60));
    }

    /**
     * A native date, as the `datetime` it always means (DXN.md §4.7):
     * its instant, with the offset its own zone carries at that instant.
     * A host value means the same DXN type with or without a schema, and
     * a native date always has a zone, which `datetime` keeps.
     */
    public static function fromNative(\DateTimeInterface $date): self
    {
        return self::fromDateTimeImmutable(\DateTimeImmutable::createFromInterface($date));
    }

    /** @return numeric-string */
    private static function asString(int|DXNInteger $value): string
    {
        return $value instanceof DXNInteger ? $value->toString() : (string) $value;
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            if ($this->epochMicroseconds instanceof DXNInteger || $other->epochMicroseconds instanceof DXNInteger) {
                return bccomp(self::asString($this->epochMicroseconds), self::asString($other->epochMicroseconds), 0);
            }
            return $this->epochMicroseconds <=> $other->epochMicroseconds;
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && self::asString($this->epochMicroseconds) === self::asString($other->epochMicroseconds)
            && $this->offsetMinutes === $other->offsetMinutes;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
