<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Binary;

use JOetjen\Dextrin\Value\DXNDuration;

/**
 * Pure constants -- CBOR tag numbers and bit-layout tables, mirroring
 * `dxn/DXN.md` §2.3 verbatim. No behavior beyond the flag<->byte
 * conversions, which exist here (not in the encoder/decoder) as one
 * shared source of truth for the bit layout, used by both directions.
 */
final class Tags
{
    // ---- registered CBOR tags used (RFC 8949 + the extensions DXN.md §2 cites) --
    /** DXN 0.2's UTC timestamp, epoch microseconds: read, never written (DXN.md §2.3, Jan, 2026-10-20). */
    public const T_TIMESTAMP_US = 1;
    public const T_BIGNUM_POS = 2;
    public const T_BIGNUM_NEG = 3;
    public const T_DECIMAL = 4;
    public const T_RATIONAL = 30;
    public const T_URI = 32;
    public const T_UUID = 37;
    // RFC 9164: an IPv4 or IPv6 address, or a prefix.
    public const T_IPV4 = 52;
    public const T_IPV6 = 54;
    public const T_STRINGREF_NAMESPACE = 256;
    public const T_STRINGREF = 25;
    public const T_SHAREABLE = 28;
    public const T_SHARED_REF = 29;

    // ---- private tag block, DXN.md §2.3 (200-221) --------------------------
    public const T_CHAR = 200;
    public const T_SYMBOL = 201;
    public const T_KEYWORD = 202;
    public const T_TUPLE = 203;
    public const T_ARRAY = 204;
    public const T_ORDERED = 205;
    public const T_SET = 206;
    public const T_SORTED_SET = 207;
    public const T_STRUCT = 208;
    public const T_DATE = 209;
    // DXN 0.2's microsecond encodings: read, never written. A tag never
    // changes its meaning, so a document 0.2 wrote reads as it was meant.
    public const T_TIME_US = 210;
    public const T_DATETIME_US = 211;
    public const T_DURATION_US = 212;
    public const T_REGEX = 213;
    public const T_CUSTOM = 214;
    /** A struct's extra fields: only ever its last item (DXN.md §2.3). */
    public const T_EXTRA = 215;

    /** A run of bits that is not whole bytes: `[bytes, count]` (DXN.md §2.3). */
    public const T_BITS = 216;

    /** A local date-time: nanoseconds since 1970-01-01T00:00:00 on the wall clock (DXN.md §2.3, Jan, 2026-10-20). */
    public const T_LOCAL_DATETIME = 217;

    /** Nanoseconds since midnight. */
    public const T_TIME = 218;

    /**
     * `[epoch-nanoseconds, offset-minutes]`, or in UTC the bare
     * epoch-nanoseconds (the smallest representation, DXN.md §2.2).
     */
    public const T_DATETIME = 219;

    /** The duration bitmask, its seventh field nanoseconds. */
    public const T_DURATION = 220;

    /** BSON's ObjectId: 12 raw bytes (Jan, 2026-10-20). */
    public const T_OBJECTID = 221;

    /** Duration bitmask field order, low bit first (`DXN.md` §2.3). */
    public const DURATION_FIELDS = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'nanoseconds'];

    /** Regex flags byte bit order, low bit first (`DXN.md` §2.3). */
    public const REGEX_FLAG_BITS = ['i', 'm', 's', 'u', 'x', 'f', 'r'];

    private function __construct()
    {
    }

    /** @param array<string, bool> $flags */
    public static function regexFlagsToByte(array $flags): int
    {
        $byte = 0;
        foreach (self::REGEX_FLAG_BITS as $bit => $flag) {
            if ($flags[$flag]) {
                $byte |= 1 << $bit;
            }
        }
        return $byte;
    }

    /** @return array<string, bool> */
    public static function regexByteToFlags(int $byte): array
    {
        $flags = [];
        foreach (self::REGEX_FLAG_BITS as $bit => $flag) {
            $flags[$flag] = ($byte & (1 << $bit)) !== 0;
        }
        return $flags;
    }

    public static function durationBitmask(DXNDuration $d): int
    {
        $mask = 0;
        foreach (self::DURATION_FIELDS as $bit => $field) {
            if ($d->$field !== null) {
                $mask |= 1 << $bit;
            }
        }
        return $mask;
    }

    /** @return list<string> */
    public static function durationFieldsPresent(int $bitmask): array
    {
        $fields = [];
        foreach (self::DURATION_FIELDS as $bit => $field) {
            if (($bitmask & (1 << $bit)) !== 0) {
                $fields[] = $field;
            }
        }
        return $fields;
    }
}
