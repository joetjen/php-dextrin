<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `decimal` (`19.99M`) -- an exact fixed-point number, `sign *
 * unscaledValue * 10^exponent`. `unscaledValue` is always a numeric
 * string (bcmath-formatted), independent of the top-level integer
 * hybrid rule ({@see DXNInteger}) -- exact decimal arithmetic needs
 * bcmath regardless of magnitude, so there's no native-`int` fast
 * path to preserve here the way there is for a bare `integer`.
 */
final class DXNDecimal implements DXNValueInterface
{
    private const DXN_TYPE = 'decimal';

    public readonly int $sign;
    /** @var numeric-string */
    public readonly string $unscaledValue;
    public readonly int $exponent;

    public function __construct(int $sign, string $unscaledValue, int $exponent)
    {
        if ($sign !== 1 && $sign !== -1) {
            throw new \InvalidArgumentException('sign must be 1 or -1');
        }
        if (!preg_match('/^[0-9]+$/D', $unscaledValue)) {
            throw new \InvalidArgumentException("unscaledValue must be an unsigned digit string: {$unscaledValue}");
        }
        $stripped = ltrim($unscaledValue, '0');
        $normalized = $stripped === '' ? '0' : $stripped;
        if (!is_numeric($normalized)) {
            throw new \LogicException("internal invariant violated: \"{$normalized}\" is not numeric");
        }
        $this->sign = $sign;
        $this->unscaledValue = $normalized;
        $this->exponent = $exponent;
    }

    public function toFloat(): float
    {
        return (float) $this->toString();
    }

    /**
     * Parses a decimal *literal* (`digits(.digits)?`, no trailing
     * `M`, no sign handling beyond a leading `-`) exactly -- unlike
     * {@see self::fromFloat()}, this never goes through a binary
     * `float` at all, so it can't introduce the representation noise
     * a literal like `19.99` would pick up by round-tripping through
     * IEEE 754 first.
     */
    public static function fromString(string $text): self
    {
        if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/D', $text, $m)) {
            throw new \InvalidArgumentException("invalid decimal literal \"{$text}\"");
        }
        $sign = $m[1] === '-' ? -1 : 1;
        $fracPart = $m[3] ?? '';
        $unscaled = $m[2] . $fracPart;
        return new self($sign, $unscaled, -strlen($fracPart));
    }

    public static function fromFloat(float $n): self
    {
        if (is_nan($n) || is_infinite($n)) {
            throw new \InvalidArgumentException('DXNDecimal cannot represent NaN/Infinity');
        }
        $sign = $n < 0 ? -1 : 1;

        // PHP's own (string) cast already uses a shortest-round-trip
        // algorithm (serialize_precision = -1 by default) -- exactly
        // what fromFloat wants ("19.99" back for 19.99, not 17 digits
        // of binary-fraction noise). It may use scientific notation
        // for very large/small magnitudes, handled below.
        $formatted = (string) abs($n);
        $exponent = 0;
        if (($ePos = stripos($formatted, 'E')) !== false) {
            $exponent = (int) substr($formatted, $ePos + 1);
            $formatted = substr($formatted, 0, $ePos);
        }
        if (str_contains($formatted, '.')) {
            [$intPart, $fracPart] = explode('.', $formatted, 2);
            $unscaled = ltrim($intPart . $fracPart, '0');
            $exponent -= strlen($fracPart);
        } else {
            $unscaled = ltrim($formatted, '0');
        }
        return new self($sign, $unscaled === '' ? '0' : $unscaled, $exponent);
    }

    /** @return numeric-string */
    public function toString(): string
    {
        $digits = $this->unscaledValue;
        $sign = ($this->sign < 0 && $digits !== '0') ? '-' : '';
        if ($this->exponent >= 0) {
            $result = $sign . $digits . str_repeat('0', $this->exponent);
        } else {
            $pointPos = strlen($digits) + $this->exponent;
            $result = $pointPos <= 0
                ? $sign . '0.' . str_repeat('0', -$pointPos) . $digits
                : $sign . substr($digits, 0, $pointPos) . '.' . substr($digits, $pointPos);
        }
        if (!is_numeric($result)) {
            throw new \LogicException("internal invariant violated: \"{$result}\" is not numeric");
        }
        return $result;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return bccomp($this->toString(), $other->toString(), max(-$this->exponent, -$other->exponent, 0) + 1);
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && $this->sign === $other->sign
            && $this->unscaledValue === $other->unscaledValue
            && $this->exponent === $other->exponent;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
