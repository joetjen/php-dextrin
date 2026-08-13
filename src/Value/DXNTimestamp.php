<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `timestamp` (`~U[YYYY-MM-DD HH:MM:SSZ]`) -- a UTC instant.
 * `epochMicroseconds` follows the project's hybrid integer rule
 * ({@see DXNInteger}). Unlike the JS port (`Date`, milliseconds
 * only), `toDateTimeImmutable()`/`fromDateTimeImmutable()` here are
 * **exact, not lossy** -- PHP's `DateTimeImmutable` natively supports
 * microsecond precision.
 */
final class DXNTimestamp implements DXNValueInterface
{
    private const DXN_TYPE = 'timestamp';

    public readonly int|DXNInteger $epochMicroseconds;

    public function __construct(int|DXNInteger $epochMicroseconds)
    {
        $this->epochMicroseconds = $epochMicroseconds;
    }

    /** @return numeric-string */
    private static function asString(int|DXNInteger $value): string
    {
        return $value instanceof DXNInteger ? $value->toString() : (string) $value;
    }

    /** @return array{0: numeric-string, 1: int} [floored whole seconds, microsecond remainder 0..999999] */
    private static function splitEpochMicroseconds(int|DXNInteger $epochMicroseconds): array
    {
        $value = self::asString($epochMicroseconds);
        $seconds = bcdiv($value, '1000000', 0);
        $remainder = bcmod($value, '1000000');
        if (bccomp($remainder, '0', 0) < 0) {
            // bcdiv/bcmod truncate toward zero; convert to floored
            // division so a negative (pre-1970) instant with a
            // sub-second remainder still lands on the correct second.
            $seconds = bcsub($seconds, '1', 0);
            $remainder = bcadd($remainder, '1000000', 0);
        }
        return [$seconds, (int) $remainder];
    }

    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        [$seconds, $microseconds] = self::splitEpochMicroseconds($this->epochMicroseconds);
        $formatted = $seconds . '.' . str_pad((string) $microseconds, 6, '0', STR_PAD_LEFT);
        $date = \DateTimeImmutable::createFromFormat('U.u', $formatted, new \DateTimeZone('UTC'));
        if ($date === false) {
            throw new \RuntimeException("failed to build DateTimeImmutable for epoch microseconds {$this->epochMicroseconds}");
        }
        return $date;
    }

    public static function fromDateTimeImmutable(\DateTimeImmutable $date): self
    {
        $seconds = $date->getTimestamp();
        $microseconds = (int) $date->format('u');
        $epochMicroseconds = bcadd(bcmul((string) $seconds, '1000000', 0), (string) $microseconds, 0);
        return new self(DXNInteger::represent($epochMicroseconds));
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
        return $other instanceof self && self::asString($this->epochMicroseconds) === self::asString($other->epochMicroseconds);
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
