<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * Shared structural equality/ordering kernel backing every DXN value,
 * native and wrapped alike -- the PHP analog of the JS port's
 * `src/values/equality.cjs`. `kindOf()` dispatches on
 * {@see DXNValueInterface::dxnType()} for wrapped classes, PHP's own
 * native types for scalars, `array_is_list()` to split `array`/`map`,
 * and "any other object" (a plain object with public properties,
 * `stdClass` or otherwise) as `struct` -- the encode-side convenience
 * described in the project's own value-model mapping.
 *
 * Cross-kind ordering is implementation-defined per `dxn/DXN.md`;
 * {@see self::KIND_RANK} is this port's own fixed choice, not
 * required to match the Elixir or JS ports' internal ordering.
 */
final class Equality
{
    private const KIND_RANK = [
        'nil' => 0, 'boolean' => 1, 'integer' => 2, 'float' => 3, 'decimal' => 4,
        'rational' => 5, 'string' => 6, 'char' => 7, 'symbol' => 8, 'keyword' => 9,
        'list' => 10, 'tuple' => 11, 'map' => 12, 'ordered-map' => 13, 'set' => 14,
        'sorted-set' => 15, 'struct' => 16, 'array' => 17, 'date' => 18, 'time' => 19,
        'timestamp' => 20, 'datetime' => 21, 'duration' => 22, 'uuid' => 23,
        'uri' => 24, 'bytes' => 25, 'regex' => 26, 'custom-tag' => 27,
    ];

    private function __construct()
    {
    }

    public static function kindOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'nil',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) ? 'array' : 'map',
            $value instanceof DXNValueInterface => $value->dxnType(),
            is_object($value) => 'struct',
            default => throw new \InvalidArgumentException('not a DXN value: ' . get_debug_type($value)),
        };
    }

    public static function dxnEquals(mixed $a, mixed $b): bool
    {
        if (self::kindOf($a) !== self::kindOf($b)) {
            return false;
        }
        // match($kindA) can't narrow $a/$b's own types from a
        // separately-computed string -- each arm below re-checks via
        // instanceof/is_*, which PHPStan (and this code) can actually
        // rely on to know what $a/$b really are.
        return match (true) {
            $a === null => true,
            is_bool($a) && is_bool($b) => $a === $b,
            is_int($a) && is_int($b) => $a === $b,
            is_string($a) && is_string($b) => $a === $b,
            is_float($a) && is_float($b) => self::floatEquals($a, $b),
            is_array($a) && is_array($b) && array_is_list($a) && array_is_list($b) => self::listEquals($a, $b),
            is_array($a) && is_array($b) => self::mapEquals($a, $b),
            $a instanceof DXNValueInterface => $a->equals($b),
            is_object($a) && is_object($b) => self::plainObjectEquals($a, $b),
            default => false,
        };
    }

    private static function floatEquals(float $a, float $b): bool
    {
        // NaN is not `===`/`==` to itself in IEEE 754, but for DXN
        // *value* identity (decoding the same NaN twice should
        // compare equal to itself), treat it as equal to itself.
        if (is_nan($a) && is_nan($b)) {
            return true;
        }
        return $a === $b;
    }

    /**
     * @param array<int|string, mixed> $a
     * @param array<int|string, mixed> $b
     */
    private static function mapEquals(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $key => $value) {
            if (!array_key_exists($key, $b) || !self::dxnEquals($value, $b[$key])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<mixed> $a
     * @param list<mixed> $b
     */
    private static function listEquals(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $value) {
            if (!self::dxnEquals($value, $b[$i])) {
                return false;
            }
        }
        return true;
    }

    private static function plainObjectEquals(object $a, object $b): bool
    {
        if ($a::class !== $b::class) {
            return false;
        }
        return self::mapEquals(get_object_vars($a), get_object_vars($b));
    }

    public static function dxnCompare(mixed $a, mixed $b): int
    {
        $kindA = self::kindOf($a);
        $kindB = self::kindOf($b);
        if ($kindA !== $kindB) {
            return self::KIND_RANK[$kindA] <=> self::KIND_RANK[$kindB];
        }
        // Same caveat as dxnEquals(): match($kindA) can't narrow $a/$b
        // themselves, so each arm re-checks via instanceof/is_*.
        return match (true) {
            $a === null => 0,
            is_bool($a) && is_bool($b) => ($a === $b) ? 0 : ($a ? 1 : -1),
            is_int($a) && is_int($b) => $a <=> $b,
            is_string($a) && is_string($b) => $a <=> $b,
            is_float($a) && is_float($b) => $a <=> $b,
            is_array($a) && is_array($b) && array_is_list($a) && array_is_list($b) => self::listCompare($a, $b),
            is_array($a) && is_array($b) => self::canonicalKey($a) <=> self::canonicalKey($b),
            $a instanceof DXNList && $b instanceof DXNList => self::listCompare($a->items, $b->items),
            $a instanceof DXNTuple && $b instanceof DXNTuple => self::listCompare($a->items, $b->items),
            $a instanceof DXNOrderedMap && $b instanceof DXNOrderedMap => self::pairsCompare($a->pairs, $b->pairs),
            $a instanceof DXNStruct => self::structCompare($a, $b),
            // decimal/rational/char/symbol/keyword/date/time/timestamp
            // /datetime/uuid/uri/bytes all implement their own natural
            // order via compareTo()'s same-class branch, safe to
            // delegate back to; set/sorted-set/duration/regex/custom
            // -tag have no meaningful natural order, so fall back to
            // a deterministic (if arbitrary) structural key compare.
            default => self::natOrCanonicalCompare($a, $b),
        };
    }

    private static function natOrCanonicalCompare(mixed $a, mixed $b): int
    {
        return match (true) {
            $a instanceof DXNInteger, $a instanceof DXNDecimal, $a instanceof DXNRational,
            $a instanceof DXNChar, $a instanceof DXNSymbol, $a instanceof DXNKeyword,
            $a instanceof DXNDate, $a instanceof DXNTime, $a instanceof DXNTimestamp,
            $a instanceof DXNDateTime, $a instanceof DXNUuid, $a instanceof DXNUri,
            $a instanceof DXNBytes => $a->compareTo($b),
            default => self::canonicalKey($a) <=> self::canonicalKey($b),
        };
    }

    /**
     * @param list<mixed> $a
     * @param list<mixed> $b
     */
    private static function listCompare(array $a, array $b): int
    {
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $c = self::dxnCompare($a[$i], $b[$i]);
            if ($c !== 0) {
                return $c;
            }
        }
        return count($a) <=> count($b);
    }

    /**
     * @param list<array{0: mixed, 1: mixed}> $a
     * @param list<array{0: mixed, 1: mixed}> $b
     */
    private static function pairsCompare(array $a, array $b): int
    {
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $c = self::dxnCompare($a[$i][0], $b[$i][0]);
            if ($c === 0) {
                $c = self::dxnCompare($a[$i][1], $b[$i][1]);
            }
            if ($c !== 0) {
                return $c;
            }
        }
        return count($a) <=> count($b);
    }

    private static function structCompare(DXNStruct $a, mixed $b): int
    {
        if (!$b instanceof DXNStruct) {
            return self::canonicalKey($a) <=> self::canonicalKey($b);
        }
        $c = $a->name <=> $b->name;
        if ($c !== 0) {
            return $c;
        }
        return self::canonicalKey($a) <=> self::canonicalKey($b);
    }

    /**
     * A stable, deterministic string encoding of `$value`'s full
     * structure -- used for set/sorted-set dedup, and as the
     * structural tie-break {@see self::dxnCompare()} falls back to
     * for kinds with no more meaningful natural order.
     */
    public static function canonicalKey(mixed $value): string
    {
        $kind = self::kindOf($value);
        return match ($kind) {
            'nil' => 'nil',
            'boolean' => 'bool:' . ($value ? '1' : '0'),
            'integer' => 'int:' . (is_int($value) ? (string) $value : $value->toString()),
            'float' => 'float:' . self::floatKey($value),
            'decimal' => "decimal:{$value->sign}:{$value->unscaledValue}:{$value->exponent}",
            'rational' => "rational:{$value->numerator}/{$value->denominator}",
            'string' => 'string:' . $value,
            'char' => 'char:' . $value->codepoint,
            'symbol' => 'symbol:' . $value->name,
            'keyword' => 'keyword:' . $value->name,
            'list' => 'list:[' . self::orderedItemsKey($value->items) . ']',
            'tuple' => 'tuple:[' . self::orderedItemsKey($value->items) . ']',
            'map' => 'map:{' . self::mapKey($value) . '}',
            'ordered-map' => 'ordered-map:{' . self::orderedPairsKey($value->pairs) . '}',
            'set' => 'set:{' . self::unorderedItemsKey($value->items) . '}',
            'sorted-set' => 'sorted-set:[' . self::orderedItemsKey($value->items) . ']',
            'struct' => self::structKey($value),
            'array' => 'array:[' . self::orderedItemsKey($value) . ']',
            'date' => "date:{$value->year}-{$value->month}-{$value->day}",
            'time' => "time:{$value->hour}:{$value->minute}:{$value->second}.{$value->microsecond}",
            'timestamp' => 'timestamp:' . (string) $value->epochMicroseconds,
            'datetime' => 'datetime:' . (string) $value->epochMicroseconds . '@' . $value->offsetMinutes,
            'duration' => self::durationKey($value),
            'uuid' => 'uuid:' . bin2hex($value->bytes),
            'uri' => 'uri:' . $value->value,
            'bytes' => 'bytes:' . bin2hex($value->data),
            'regex' => 'regex:' . $value->pattern . '|' . self::flagsKey($value->flags),
            'custom-tag' => 'custom-tag:' . $value->name . '=' . self::canonicalKey($value->value),
            default => throw new \LogicException("unreachable kind: {$kind}"),
        };
    }

    private static function floatKey(float $v): string
    {
        if (is_nan($v)) {
            return 'nan';
        }
        if (is_infinite($v)) {
            return $v > 0 ? 'inf' : '-inf';
        }
        return (string) $v;
    }

    /** @param list<mixed> $items */
    private static function orderedItemsKey(array $items): string
    {
        return implode(',', array_map(self::canonicalKey(...), $items));
    }

    /** @param list<mixed> $items */
    private static function unorderedItemsKey(array $items): string
    {
        $parts = array_map(self::canonicalKey(...), $items);
        sort($parts);
        return implode(',', $parts);
    }

    /** @param array<int|string, mixed> $map */
    private static function mapKey(array $map): string
    {
        $parts = [];
        foreach ($map as $key => $value) {
            $parts[] = self::canonicalKey($key) . '=>' . self::canonicalKey($value);
        }
        sort($parts);
        return implode(',', $parts);
    }

    /** @param list<array{0: mixed, 1: mixed}> $pairs */
    private static function orderedPairsKey(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as [$key, $value]) {
            $parts[] = self::canonicalKey($key) . '=>' . self::canonicalKey($value);
        }
        return implode(',', $parts);
    }

    private static function structKey(DXNStruct $struct): string
    {
        if ($struct->isPositional()) {
            return "struct:{$struct->name}:positional:[" . self::orderedItemsKey($struct->values()) . ']';
        }
        $parts = [];
        foreach ($struct->pairs() as [$key, $value]) {
            $parts[] = $key . '=>' . self::canonicalKey($value);
        }
        sort($parts);
        return "struct:{$struct->name}:keyed:{" . implode(',', $parts) . '}';
    }

    private static function durationKey(DXNDuration $duration): string
    {
        $field = static fn (?int $v): string => $v === null ? '_' : (string) $v;
        return 'duration:' . implode(',', [
            $field($duration->years), $field($duration->months), $field($duration->weeks),
            $field($duration->days), $field($duration->hours), $field($duration->minutes),
            $field($duration->microseconds),
        ]);
    }

    /** @param array<string, bool> $flags */
    private static function flagsKey(array $flags): string
    {
        ksort($flags);
        $parts = [];
        foreach ($flags as $name => $set) {
            if ($set) {
                $parts[] = $name;
            }
        }
        return implode('', $parts);
    }
}
