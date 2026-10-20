<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `rational` (`22/7`) -- an exact ratio, never auto-reduced (`4/2`
 * and `2/1` are structurally distinct values, same as the Elixir and
 * JS ports). `numerator`/`denominator` are numeric strings, same
 * "always bcmath, regardless of magnitude" rule as {@see DXNDecimal}.
 */
final class DXNRational implements DXNValueInterface
{
    private const DXN_TYPE = 'rational';

    /** @var numeric-string */
    public readonly string $numerator;
    /** @var numeric-string */
    public readonly string $denominator;

    public function __construct(string $numerator, string $denominator)
    {
        if (!preg_match('/^-?[0-9]+$/D', $numerator)) {
            throw new \InvalidArgumentException("numerator must be a signed digit string: {$numerator}");
        }
        if (!preg_match('/^[0-9]+$/D', $denominator)) {
            throw new \InvalidArgumentException("denominator must be a positive digit string: {$denominator}");
        }
        $normalizedDenominator = self::normalize($denominator);
        // Checked after normalization -- "00" is unsigned-digits-only
        // per the regex above but denotes zero just as much as "0"
        // does once leading zeros are stripped.
        if ($normalizedDenominator === '0') {
            throw new \InvalidArgumentException("denominator must be a positive digit string: {$denominator}");
        }
        $this->numerator = self::normalize($numerator);
        $this->denominator = $normalizedDenominator;
    }

    /** @return numeric-string */
    private static function normalize(string $s): string
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

    public function toFloat(): float
    {
        return ((float) $this->numerator) / ((float) $this->denominator);
    }

    /**
     * Exact, not approximate: every finite PHP `float` is a binary
     * fraction (mantissa * 2^exponent), so decomposing it yields an
     * exact rational with a power-of-2 denominator.
     */
    public static function fromFloat(float $n): self
    {
        if (is_nan($n) || is_infinite($n)) {
            throw new \InvalidArgumentException('DXNRational cannot represent NaN/Infinity');
        }
        if ($n === 0.0) {
            return new self('0', '1');
        }
        [$mantissa, $exponent] = self::decompose($n);
        if ($exponent >= 0) {
            return new self(bcmul($mantissa, bcpow('2', (string) $exponent, 0), 0), '1');
        }
        return new self($mantissa, bcpow('2', (string) (-$exponent), 0));
    }

    /** @return array{0: numeric-string, 1: int} [signed integer mantissa (as a numeric string), base-2 exponent] */
    private static function decompose(float $n): array
    {
        // Explicit big-endian on both sides ('E' pack / 'J' unpack) --
        // mixing a big-endian pack with a machine-order unpack ('Q')
        // would silently byte-reverse the bits on a little-endian host.
        $bits = unpack('J', pack('E', $n));
        if ($bits === false) {
            throw new \RuntimeException('failed to unpack float bits');
        }
        $raw = $bits[1];
        $sign = ($raw >> 63) & 1;
        $rawExponent = (int) (($raw >> 52) & 0x7FF);
        $rawMantissa = $raw & 0xFFFFFFFFFFFFF;

        if ($rawExponent === 0) {
            // Subnormal.
            $mantissa = (string) $rawMantissa;
            $exponent = -1074;
        } else {
            $mantissa = bcadd((string) $rawMantissa, bcpow('2', '52', 0), 0);
            $exponent = $rawExponent - 1075;
        }

        // Strip trailing zero bits so the mantissa/exponent pair stays minimal.
        while ($mantissa !== '0' && bcmod($mantissa, '2') === '0') {
            $mantissa = bcdiv($mantissa, '2', 0);
            $exponent++;
        }

        $result = $sign === 1 ? "-{$mantissa}" : $mantissa;
        if (!is_numeric($result)) {
            throw new \LogicException("internal invariant violated: \"{$result}\" is not numeric");
        }
        return [$result, $exponent];
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return bccomp(
                bcmul($this->numerator, $other->denominator, 0),
                bcmul($other->numerator, $this->denominator, 0),
                0,
            );
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && $this->numerator === $other->numerator
            && $this->denominator === $other->denominator;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
