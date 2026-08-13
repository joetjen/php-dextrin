<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Binary;

use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNDuration;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTime;
use JOetjen\Dextrin\Value\DXNTimestamp;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUri;
use JOetjen\Dextrin\Value\DXNUuid;
use JOetjen\Dextrin\Value\DXNValueInterface;

/**
 * `.dxnb` encoder -- a recursive walk over CBOR's major types plus a
 * fixed tag dispatch table, matching `dxn/DXN.md` §2.2's table row
 * order.
 *
 * **Scope note**: value sharing (`dxn/DXN.md` §2.5, tags 28/29) is
 * decode-only for now, same as the JS port -- the spec requires a
 * conforming *decoder* to accept it but never requires an *encoder*
 * to produce it. Every document round-trips correctly either way;
 * producing shared references would only ever change output size,
 * never semantics.
 *
 * Envelope (`magic` `version` `cbor_item`, `dxn/DXN.md` §2.1) is
 * written once, at {@see self::encode()} itself; everything below
 * that is {@see self::encodeItem()}, one recursive method keyed on
 * the PHP value's own shape.
 */
final class Encoder
{
    private const MAGIC = 'DX';
    private const VERSION = "\x01";

    private function __construct()
    {
    }

    public static function encode(mixed $value): string
    {
        return self::MAGIC . self::VERSION . self::encodeItem($value);
    }

    private static function encodeItem(mixed $value): string
    {
        return match (true) {
            $value === null => Writer::head(7, '22'),
            $value === false => Writer::head(7, '20'),
            $value === true => Writer::head(7, '21'),
            $value instanceof DXNInteger, is_int($value) => Writer::integerItem($value),
            is_float($value) => Writer::floatItem($value),
            is_string($value) => Writer::textItem($value),

            $value instanceof DXNDecimal => self::encodeDecimal($value),
            $value instanceof DXNRational => Writer::tag(Tags::T_RATIONAL, Writer::arrayOf([
                Writer::integerItem(DXNInteger::represent($value->numerator)),
                Writer::integerItem(DXNInteger::represent($value->denominator)),
            ])),
            $value instanceof DXNChar => Writer::tag(Tags::T_CHAR, Writer::integerItem($value->codepoint)),
            $value instanceof DXNSymbol => Writer::tag(Tags::T_SYMBOL, Writer::textItem($value->name)),
            $value instanceof DXNKeyword => Writer::tag(Tags::T_KEYWORD, Writer::textItem($value->name)),
            $value instanceof DXNBytes => Writer::bytesItem($value->data),

            $value instanceof DXNList => Writer::arrayOf(array_map(self::encodeItem(...), $value->items)),
            $value instanceof DXNTuple => Writer::tag(Tags::T_TUPLE, Writer::arrayOf(array_map(self::encodeItem(...), $value->items))),
            $value instanceof DXNSet => Writer::tag(Tags::T_SET, Writer::arrayOf(array_map(self::encodeItem(...), $value->items))),
            $value instanceof DXNSortedSet => Writer::tag(Tags::T_SORTED_SET, Writer::arrayOf(array_map(self::encodeItem(...), $value->items))),
            $value instanceof DXNOrderedMap => Writer::tag(Tags::T_ORDERED, Writer::mapOf(self::encodePairs($value->pairs))),

            is_array($value) && array_is_list($value) => Writer::tag(Tags::T_ARRAY, Writer::arrayOf(array_map(self::encodeItem(...), $value))),
            is_array($value) => Writer::mapOf(self::encodeMapEntries($value)),

            $value instanceof DXNStruct => self::encodeStruct($value->name, $value->isPositional() ? $value->values() : array_map(static fn (array $p) => $p[1], $value->pairs())),

            $value instanceof DXNDate => Writer::tag(Tags::T_DATE, Writer::integerItem(self::epochDays($value))),
            $value instanceof DXNTime => Writer::tag(Tags::T_TIME, Writer::integerItem(self::totalMicroseconds($value))),
            $value instanceof DXNTimestamp => Writer::tag(Tags::T_TIMESTAMP, Writer::integerItem($value->epochMicroseconds)),
            $value instanceof DXNDateTime => Writer::tag(Tags::T_DATETIME_OFFSET, Writer::arrayOf([
                Writer::integerItem($value->epochMicroseconds),
                Writer::integerItem($value->offsetMinutes),
            ])),
            $value instanceof DXNDuration => self::encodeDuration($value),

            $value instanceof DXNUuid => Writer::tag(Tags::T_UUID, Writer::bytesItem($value->bytes)),
            $value instanceof DXNUri => Writer::tag(Tags::T_URI, Writer::textItem($value->value)),
            $value instanceof DXNRegex => Writer::tag(Tags::T_REGEX, Writer::arrayOf([
                Writer::textItem($value->pattern),
                Writer::integerItem(Tags::regexFlagsToByte($value->flags)),
            ])),
            $value instanceof DXNCustomTag => Writer::tag(Tags::T_CUSTOM, Writer::arrayOf([Writer::textItem($value->name), self::encodeItem($value->value)])),

            // Any plain PHP object with public properties (not just
            // stdClass) auto-encodes as a positional struct named
            // after its own class -- the same encode-side convenience
            // Text\Printer offers, extended to binary for consistency.
            // A Closure is categorically code, not data, so it's
            // excluded the same way Printer excludes it.
            is_object($value) && !$value instanceof DXNValueInterface && !$value instanceof \Closure => self::encodeStruct(
                self::shortClassName($value::class),
                array_values(get_object_vars($value)),
            ),
            default => throw new \InvalidArgumentException('cannot encode value with no DXN representation: ' . get_debug_type($value)),
        };
    }

    /**
     * @param list<array{0: mixed, 1: mixed}> $pairs
     * @return list<array{0: string, 1: string}>
     */
    private static function encodePairs(array $pairs): array
    {
        return array_map(static fn (array $p): array => [self::encodeItem($p[0]), self::encodeItem($p[1])], $pairs);
    }

    /**
     * @param array<int|string, mixed> $map
     * @return list<array{0: string, 1: string}>
     */
    private static function encodeMapEntries(array $map): array
    {
        $pairs = [];
        foreach ($map as $key => $value) {
            $pairs[] = [self::encodeItem($key), self::encodeItem($value)];
        }
        return $pairs;
    }

    /**
     * `.dxnb` structs are always positional (`dxn/DXN.md` §2.1) -- a
     * keyed `DXNStruct` (no schema known) falls back to its pairs' own
     * iteration order, the only field order available without a
     * schema.
     * @param list<mixed> $values
     */
    private static function encodeStruct(string $name, array $values): string
    {
        return Writer::tag(Tags::T_STRUCT, Writer::arrayOf([Writer::textItem($name), ...array_map(self::encodeItem(...), $values)]));
    }

    private static function shortClassName(string $className): string
    {
        return substr(strrchr('\\' . $className, '\\') ?: '', 1);
    }

    private static function encodeDecimal(DXNDecimal $value): string
    {
        $mantissa = $value->sign < 0 && $value->unscaledValue !== '0' ? "-{$value->unscaledValue}" : $value->unscaledValue;
        return Writer::tag(Tags::T_DECIMAL, Writer::arrayOf([
            Writer::integerItem($value->exponent),
            Writer::integerItem(DXNInteger::represent($mantissa)),
        ]));
    }

    private static function epochDays(DXNDate $date): int
    {
        $utcMidnight = $date->toDateTimeImmutable();
        $epoch = new \DateTimeImmutable('1970-01-01T00:00:00Z');
        return intdiv($utcMidnight->getTimestamp() - $epoch->getTimestamp(), 86_400);
    }

    private static function totalMicroseconds(DXNTime $time): int
    {
        return $time->hour * 3_600_000_000
            + $time->minute * 60_000_000
            + $time->second * 1_000_000
            + $time->microsecond;
    }

    private static function encodeDuration(DXNDuration $d): string
    {
        $bitmask = Tags::durationBitmask($d);
        $fields = Tags::durationFieldsPresent($bitmask);
        $items = [Writer::integerItem($bitmask)];
        foreach ($fields as $field) {
            $fieldValue = $d->$field;
            if ($fieldValue === null) {
                throw new \LogicException("unreachable: durationFieldsPresent() named a null field: {$field}");
            }
            $items[] = Writer::integerItem($fieldValue);
        }
        return Writer::tag(Tags::T_DURATION, Writer::arrayOf($items));
    }
}
