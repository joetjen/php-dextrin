<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Binary;

/**
 * CBOR's half- and single-precision floats (RFC 8949 §3.3, Appendix D),
 * which `.dxnb` writes when they hold a value exactly -- the smallest
 * representation (DXN.md §2.2, Jan, 2026-10-20). Named for what it
 * chooses, as `Float` is a name PHP keeps for itself.
 *
 * Everything here works on the double's bits rather than on logarithms,
 * so "exactly" means exactly: a float fits a width when no bit of it is
 * lost there, the sign of a zero included.
 */
final class FloatWidth
{
    /** A double's 52 mantissa bits. */
    private const MANTISSA = 0xFFFFFFFFFFFFF;

    private function __construct()
    {
    }

    /** A half-precision float's 16 bits as the float they hold. */
    public static function fromHalf(int $bits): float
    {
        $sign = ($bits >> 15) === 1 ? -1.0 : 1.0;
        $exponent = ($bits >> 10) & 0x1F;
        $fraction = $bits & 0x3FF;
        return match (true) {
            $exponent === 31 && $fraction !== 0 => NAN,
            $exponent === 31 => $sign * INF,
            $exponent === 0 => $sign * $fraction * 2 ** -24,
            default => $sign * (1024 + $fraction) * 2 ** ($exponent - 25),
        };
    }

    /**
     * `$f`'s half-precision bits, when half precision holds it exactly;
     * NaN is the quiet NaN `0x7E00`, the infinities `0x7C00` and `0xFC00`.
     */
    public static function half(float $f): ?int
    {
        if (is_nan($f)) {
            return 0x7E00;
        }
        $bits = self::doubleBits($f);
        $sign = (($bits >> 63) & 1) << 15;
        $exponent = (($bits >> 52) & 0x7FF) - 1023;
        $mantissa = $bits & self::MANTISSA;
        if (is_infinite($f)) {
            return $sign | 0x7C00;
        }
        if ($f === 0.0) {
            return $sign;
        }
        // A normal half: its exponent in -14..15 and only the top ten
        // mantissa bits set.
        if ($exponent >= -14 && $exponent <= 15) {
            return ($mantissa & ((1 << 42) - 1)) === 0 ? $sign | (($exponent + 15) << 10) | ($mantissa >> 42) : null;
        }
        // A subnormal half: a multiple of 2^-24 below 2^-14.
        if ($exponent >= -24 && $exponent < -14) {
            $significand = (1 << 52) | $mantissa;
            $shift = 28 - $exponent;
            return ($significand & ((1 << $shift) - 1)) === 0 ? $sign | ($significand >> $shift) : null;
        }
        return null;
    }

    /** Whether single precision holds `$f` exactly. */
    public static function single(float $f): bool
    {
        $unpacked = unpack('G', pack('G', $f));
        return $unpacked !== false && pack('E', $unpacked[1]) === pack('E', $f);
    }

    /** `$f`'s IEEE 754 double bits, the sign bit PHP's int sign. */
    private static function doubleBits(float $f): int
    {
        $unpacked = unpack('J', pack('E', $f));
        if ($unpacked === false) {
            throw new \LogicException("unreachable: unpack('J', ...) failed on eight bytes");
        }
        return $unpacked[1];
    }
}
