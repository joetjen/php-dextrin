<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `uri` (`@uri "..."`) -- a raw string, not parsed, same
 * round-trip-safety rationale as the Elixir and JS ports.
 */
final class DXNUri implements DXNValueInterface
{
    private const DXN_TYPE = 'uri';

    public readonly string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /** Trivial passthrough, kept for API consistency with the rest of the wrapper types. */
    public static function fromString(string $s): self
    {
        return new self($s);
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return $this->value <=> $other->value;
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->value === $other->value;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
