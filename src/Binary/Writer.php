<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Binary;

use JOetjen\Dextrin\Value\DXNInteger;

/**
 * Low-level CBOR item builders shared by {@see Encoder} -- raw
 * major-type/head construction, kept separate from the DXN-type
 * -dispatch logic there.
 *
 * **The one sharp edge in this file**: CBOR major 0's argument can
 * reach `2^64-1` (via the 8-byte "additional info 27" head form),
 * which *exceeds* `PHP_INT_MAX` (`2^63-1`, PHP's `int` is signed
 * 64-bit) -- unlike the JS port, which has `bigint` with no upper
 * bound to lean on. {@see self::head()} therefore takes its argument
 * as a numeric string throughout and does every size comparison via
 * `bccomp()`, only ever casting to a native `int` once a branch has
 * already proven the value small enough (`<= 4294967295`, always
 * safely within `PHP_INT_MAX`) -- the 8-byte case is built byte-by
 * -byte via `bcdiv`/`bcmod` instead, exactly the class of bug this
 * project's plan flagged in advance (mirroring the JS port's own
 * `writeBigUInt64BE` header-byte-offset bug, a different bug in the
 * same "native integer type doesn't cover CBOR's own range" family).
 */
final class Writer
{
    /** 2^64 - 1, the largest value CBOR's own head encoding (major 0/1's argument) can hold. */
    private const UINT64_MAX = '18446744073709551615';

    private function __construct()
    {
    }

    /**
     * CBOR item header: major type (0-7) + argument, shortest form.
     * @param numeric-string $n non-negative, <= 2^64-1.
     */
    public static function head(int $major, string $n): string
    {
        if (bccomp($n, '23', 0) <= 0) {
            return chr(($major << 5) | (int) $n);
        }
        if (bccomp($n, '255', 0) <= 0) {
            return chr(($major << 5) | 24) . chr((int) $n);
        }
        if (bccomp($n, '65535', 0) <= 0) {
            return chr(($major << 5) | 25) . pack('n', (int) $n);
        }
        if (bccomp($n, '4294967295', 0) <= 0) {
            return chr(($major << 5) | 26) . pack('N', (int) $n);
        }
        if (bccomp($n, self::UINT64_MAX, 0) > 0) {
            throw new \InvalidArgumentException("value {$n} exceeds CBOR's 64-bit unsigned integer range");
        }
        return chr(($major << 5) | 27) . self::uint64BEBytes($n);
    }

    /**
     * Major 7's float marker (additional info 27, "double follows") --
     * deliberately not `head(7, "27")`: for every other major type,
     * `head`'s job is "encode this integer using the fewest bytes CBOR
     * allows," but major 7's additional-info value of 27 has to mean
     * literally "float64 follows," not "the number 27, shortest form"
     * -- `head(7, "27")` would emit two bytes instead of the required
     * single `0xfb` byte, a genuinely different, non-conformant
     * encoding.
     */
    public static function floatHead(): string
    {
        return "\xfb";
    }

    /**
     * A float in the shortest of CBOR's three widths that holds it
     * exactly (DXN.md §2.2, Jan, 2026-10-20: the smallest
     * representation): half, single, then double. NaN and the
     * infinities fit in half; -0.0 keeps its sign.
     */
    public static function floatItem(float $n): string
    {
        $half = FloatWidth::half($n);
        if ($half !== null) {
            return "\xf9" . pack('n', $half);
        }
        if (FloatWidth::single($n)) {
            return "\xfa" . pack('G', $n);
        }
        return self::floatHead() . pack('E', $n);
    }

    public static function tag(int $tagNumber, string $itemBytes): string
    {
        return self::head(6, (string) $tagNumber) . $itemBytes;
    }

    /** @param list<string> $items already-encoded item byte strings. */
    public static function arrayOf(array $items): string
    {
        return self::head(4, (string) count($items)) . implode('', $items);
    }

    /** @param list<array{0: string, 1: string}> $pairs already-encoded key/value byte string pairs. */
    public static function mapOf(array $pairs): string
    {
        $body = '';
        foreach ($pairs as [$key, $value]) {
            $body .= $key . $value;
        }
        return self::head(5, (string) count($pairs)) . $body;
    }

    public static function textItem(string $s): string
    {
        return self::head(3, (string) strlen($s)) . $s;
    }

    public static function bytesItem(string $data): string
    {
        return self::head(2, (string) strlen($data)) . $data;
    }

    /**
     * A DXN `integer` (arbitrary precision, hybrid native `int`/
     * {@see DXNInteger}) as CBOR major 0/1, or tag 2/3 (magnitude
     * bytes) once it overflows 64 bits -- exactly `dxn/DXN.md` §2.2's
     * integrity requirement.
     */
    public static function integerItem(int|DXNInteger $i): string
    {
        $s = $i instanceof DXNInteger ? $i->toString() : (string) $i;
        if (bccomp($s, '0', 0) >= 0) {
            if (bccomp($s, self::UINT64_MAX, 0) <= 0) {
                return self::head(0, $s);
            }
            return self::tag(Tags::T_BIGNUM_POS, self::bytesItem(self::bigDigitsToBytes($s)));
        }
        $arg = bcsub('-1', $s, 0); // -1 - i, always non-negative since i < 0
        if (bccomp($arg, self::UINT64_MAX, 0) <= 0) {
            return self::head(1, $arg);
        }
        return self::tag(Tags::T_BIGNUM_NEG, self::bytesItem(self::bigDigitsToBytes($arg)));
    }

    /** @param numeric-string $n non-negative, arbitrary magnitude. */
    private static function uint64BEBytes(string $n): string
    {
        return str_pad(self::bigDigitsToBytes($n), 8, "\x00", STR_PAD_LEFT);
    }

    /**
     * Big-endian minimal-length magnitude bytes for a non-negative
     * numeric string of any size -- built via repeated `bcdiv`/`bcmod`
     * rather than any native-int arithmetic, so this has no upper
     * bound the way casting to `int` would.
     * @param numeric-string $n non-negative.
     */
    public static function bigDigitsToBytes(string $n): string
    {
        if ($n === '0') {
            return "\x00";
        }
        $bytes = [];
        $remaining = $n;
        while (bccomp($remaining, '0', 0) > 0) {
            $bytes[] = chr((int) bcmod($remaining, '256'));
            $remaining = bcdiv($remaining, '256', 0);
        }
        return implode('', array_reverse($bytes));
    }

    /**
     * The reverse of {@see self::bigDigitsToBytes()}: raw big-endian
     * bytes to a non-negative numeric string, any length.
     * @return numeric-string
     */
    public static function bytesToBigDigits(string $bytes): string
    {
        $n = '0';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $n = bcadd(bcmul($n, '256', 0), (string) ord($bytes[$i]), 0);
        }
        return $n;
    }
}
