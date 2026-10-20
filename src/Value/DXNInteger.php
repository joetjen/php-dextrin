<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * An arbitrary-precision integer, backed by `bcmath` (no `ext-gmp`
 * assumed available) -- only ever constructed for a magnitude outside
 * `PHP_INT_MIN..PHP_INT_MAX`. {@see DXNInteger::represent()} is the
 * canonical entry point embodying the project's hybrid integer rule:
 * a value that fits stays a native `int` everywhere (ordinary `+`,
 * `-`, comparisons just work); only genuine overflow reaches this
 * class, whose own arithmetic is method calls, not operators -- PHP
 * has no operator overloading for arithmetic.
 */
final class DXNInteger implements DXNValueInterface
{
    private const DXN_TYPE = 'integer';

    /**
     * Canonical decimal digits, no leading zeros, "-" only if
     * negative and nonzero.
     * @var numeric-string
     */
    private readonly string $value;

    public function __construct(string $value)
    {
        if (!preg_match('/^-?[0-9]+$/D', $value)) {
            throw new \InvalidArgumentException("not a valid integer string: {$value}");
        }
        $this->value = self::normalizeDigits($value);
    }

    public static function fromInt(int $n): self
    {
        return new self((string) $n);
    }

    public static function fromString(string $numeric): self
    {
        return new self($numeric);
    }

    /**
     * The hybrid rule's entry point: returns a native `int` when
     * `$numeric` fits in `PHP_INT_MIN..PHP_INT_MAX`, a `DXNInteger`
     * otherwise. Callers (the lexer, the binary decoder) should use
     * this rather than deciding the boundary themselves.
     */
    public static function represent(string $numeric): int|self
    {
        $normalized = self::normalizeDigits($numeric);
        if (self::fitsInIntString($normalized)) {
            return (int) $normalized;
        }
        return new self($normalized);
    }

    /** @return numeric-string */
    private static function normalizeDigits(string $s): string
    {
        $negative = str_starts_with($s, '-');
        $digits = ltrim($negative ? substr($s, 1) : $s, '0');
        if ($digits === '') {
            $digits = '0';
        }
        $result = ($negative && $digits !== '0') ? "-{$digits}" : $digits;
        if (!is_numeric($result)) {
            throw new \LogicException("internal invariant violated: \"{$result}\" is not numeric");
        }
        return $result;
    }

    /** @param numeric-string $normalized */
    private static function fitsInIntString(string $normalized): bool
    {
        return bccomp($normalized, (string) PHP_INT_MIN, 0) >= 0
            && bccomp($normalized, (string) PHP_INT_MAX, 0) <= 0;
    }

    /** @return numeric-string */
    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function fitsInInt(): bool
    {
        return self::fitsInIntString($this->value);
    }

    public function toInt(): int
    {
        if (!$this->fitsInInt()) {
            throw new \OverflowException("DXNInteger {$this->value} does not fit in a native int");
        }
        return (int) $this->value;
    }

    /** @return numeric-string */
    private static function operand(int|self $other): string
    {
        return $other instanceof self ? $other->value : (string) $other;
    }

    public function plus(int|self $other): self
    {
        return new self(bcadd($this->value, self::operand($other), 0));
    }

    public function minus(int|self $other): self
    {
        return new self(bcsub($this->value, self::operand($other), 0));
    }

    public function times(int|self $other): self
    {
        return new self(bcmul($this->value, self::operand($other), 0));
    }

    public function negate(): self
    {
        return new self(bcmul($this->value, '-1', 0));
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self || is_int($other)) {
            return bccomp($this->value, self::operand($other), 0);
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        if ($other instanceof self || is_int($other)) {
            return $this->compareTo($other) === 0;
        }
        return false;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
