<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Binary;

use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Registry;
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
 *
 * An optional `$registry` lets a genuine, registered application class
 * instance be encoded as its *schema's* named struct rather than one
 * named after its own PHP class, when the two differ -- see {@see
 * \JOetjen\Dextrin\Text\Printer}'s own doc on the identical text-side
 * behavior.
 */
final class Encoder
{
    private const MAGIC = 'DX';
    private const VERSION = "\x01";

    private function __construct()
    {
    }

    public static function encode(mixed $value, ?Registry $registry = null): string
    {
        return self::MAGIC . self::VERSION . self::encodeItem($value, $registry);
    }

    private static function encodeItem(mixed $value, ?Registry $registry): string
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

            $value instanceof DXNList => Writer::arrayOf(array_map(
                static fn (mixed $v): string => self::encodeItem($v, $registry),
                $value->items,
            )),
            $value instanceof DXNTuple => Writer::tag(Tags::T_TUPLE, Writer::arrayOf(array_map(
                static fn (mixed $v): string => self::encodeItem($v, $registry),
                $value->items,
            ))),
            $value instanceof DXNSet => Writer::tag(Tags::T_SET, Writer::arrayOf(array_map(
                static fn (mixed $v): string => self::encodeItem($v, $registry),
                $value->items,
            ))),
            $value instanceof DXNSortedSet => Writer::tag(Tags::T_SORTED_SET, Writer::arrayOf(array_map(
                static fn (mixed $v): string => self::encodeItem($v, $registry),
                $value->items,
            ))),
            $value instanceof DXNOrderedMap => Writer::tag(Tags::T_ORDERED, Writer::mapOf(self::encodePairs($value->pairs, $registry))),
            $value instanceof \JOetjen\Dextrin\Value\DXNMap => Writer::mapOf(self::encodePairs($value->pairs(), $registry)),

            is_array($value) && array_is_list($value) => Writer::tag(Tags::T_ARRAY, Writer::arrayOf(array_map(
                static fn (mixed $v): string => self::encodeItem($v, $registry),
                $value,
            ))),
            is_array($value) => Writer::mapOf(self::encodeMapEntries($value, $registry)),

            $value instanceof DXNStruct => self::encodeStruct($value, $registry),

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
            $value instanceof DXNCustomTag => Writer::tag(Tags::T_CUSTOM, Writer::arrayOf([Writer::textItem($value->name), self::encodeItem($value->value, $registry)])),

            // Any plain PHP object with public properties (not just
            // stdClass) auto-encodes as a positional struct -- the
            // same encode-side convenience Text\Printer offers,
            // extended to binary for consistency. A Closure is
            // categorically code, not data, so it's excluded the same
            // way Printer excludes it.
            is_object($value) && !$value instanceof DXNValueInterface && !$value instanceof \Closure => self::encodePlainObjectAsStruct($value, $registry),
            default => throw \JOetjen\Dextrin\UnsupportedValue::of($value),
        };
    }

    /**
     * @param list<array{0: mixed, 1: mixed}> $pairs
     * @return list<array{0: string, 1: string}>
     */
    private static function encodePairs(array $pairs, ?Registry $registry): array
    {
        \JOetjen\Dextrin\MapKey::checkPairs($pairs);
        return array_map(
            static fn (array $p): array => [self::encodeItem($p[0], $registry), self::encodeItem($p[1], $registry)],
            $pairs,
        );
    }

    /**
     * A bare PHP array's own key already tells
     * `MapBuilder::toDxnMapKey()` apart a `DXNKeyword` key from a
     * `string` key with the same text (see that class's own doc) --
     * a keyword-originated key encodes under `Tags::T_KEYWORD`, same
     * as any other `DXNKeyword` value; a genuinely string-typed key
     * encodes as a plain untagged text item, same as before.
     *
     * @param array<int|string, mixed> $map
     * @return list<array{0: string, 1: string}>
     */
    private static function encodeMapEntries(array $map, ?Registry $registry): array
    {
        $pairs = [];
        foreach ($map as $key => $value) {
            $dxnKey = MapBuilder::toDxnMapKey($key);
            $encodedKey = $dxnKey instanceof DXNKeyword
                ? Writer::tag(Tags::T_KEYWORD, Writer::textItem($dxnKey->name))
                : self::encodeItem($dxnKey, $registry);
            $pairs[] = [$encodedKey, self::encodeItem($value, $registry)];
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
    /**
     * `.dxnb` structs are always positional (DXN.md §2.1), written with
     * the type name `Name(3)` that {@see Registry::asWritten()} says --
     * the latest loaded version of its schema, or its own (§2.4, §4.8).
     */
    private static function encodeStruct(DXNStruct $struct, ?Registry $registry): string
    {
        $written = $registry?->asWritten($struct) ?? $struct;
        $items = [
            Writer::textItem(DXNStruct::typeName($written->name, $written->version)),
            ...array_map(static fn (mixed $v): string => self::encodeItem($v, $registry), self::positionalValues($struct, $registry)),
        ];
        // Extra fields, when there are any, as one trailing tag-215 map in
        // the order they were written (DXN.md §2.3).
        if ($struct->extra !== []) {
            $items[] = Writer::tag(Tags::T_EXTRA, Writer::mapOf(self::encodePairs($struct->extra, $registry)));
        }
        return Writer::tag(Tags::T_STRUCT, Writer::arrayOf($items));
    }

    /**
     * A struct's field values in the order the wire wants them. A keyed
     * struct is put in its schema's field order, because a position is
     * what the wire has; with no schema there is no order to consult, and
     * the pairs' own order is all there is.
     *
     * @return list<mixed>
     */
    private static function positionalValues(DXNStruct $struct, ?Registry $registry): array
    {
        if ($struct->isPositional()) {
            return $struct->values();
        }
        $found = $registry?->fetchStructSchema($struct->name);
        if ($found === null) {
            return array_map(static fn (array $p): mixed => $p[1], $struct->pairs());
        }
        $named = [];
        foreach ($struct->pairs() as [$key, $value]) {
            $named[$key] = $value;
        }
        return array_map(static fn (string $name): mixed => $named[$name] ?? null, $found->compiled->fieldNames());
    }

    private static function shortClassName(string $className): string
    {
        return substr(strrchr('\\' . $className, '\\') ?: '', 1);
    }

    /**
     * Same registry-first precedence as
     * {@see \JOetjen\Dextrin\Text\Printer::printPlainObjectAsStruct()}'s
     * own doc: a struct name registered for `$value`'s class takes
     * priority (schema's own field order, not `$value`'s
     * declared-property order); a registered tag encoder takes
     * priority over the generic struct-by-class-name fallback next,
     * since that's a scalar-wrapping extension, not a struct one.
     */
    private static function encodePlainObjectAsStruct(object $value, ?Registry $registry): string
    {
        $name = $registry?->fetchSchemaNameForModule($value::class);
        if ($name !== null) {
            $found = $registry->fetchStructSchema($name);
            if ($found !== null) {
                $values = array_map(static fn (array $p): mixed => $p[1], $found->compiled->fieldValues($value));
                return self::encodeStruct(DXNStruct::positional($name, $values), $registry);
            }
        }

        $tagEncoder = $registry?->fetchTagEncoder($value::class);
        if ($tagEncoder !== null) {
            [$tagName, $encoder] = $tagEncoder;
            return Writer::tag(Tags::T_CUSTOM, Writer::arrayOf([Writer::textItem($tagName), self::encodeItem($encoder($value), $registry)]));
        }

        return self::encodeStruct(
            DXNStruct::positional(self::shortClassName($value::class), array_values(get_object_vars($value))),
            $registry,
        );
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
