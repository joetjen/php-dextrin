<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNTuple;

/**
 * Internal only -- never appears in a value handed back to
 * `Dextrin::decode()`/`decodeBinary()`'s caller.
 *
 * Tracks which schema produced a materialized value, so an outer
 * `{form: 'reference', name}` check can verify identity even after
 * materialization has replaced the value with a plain field map that
 * carries no name of its own -- unlike an *opaque*
 * {@see DXNStruct}, which already carries its own name and never
 * needed this. {@see Validator}'s `materialize()` (decode) and
 * `wrapAndCheck()` (encode-time validation) both wrap every
 * successfully-checked struct's result in one of these; `strip()`
 * removes every occurrence, recursively, once the value reaches
 * somewhere that isn't itself another schema check.
 */
final class Validated
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
    ) {
    }

    /** Recursively removes every `Validated` wrapper from a decoded value tree. */
    public static function strip(mixed $value): mixed
    {
        if ($value instanceof self) {
            return self::strip($value->value);
        }
        // A bare PHP array already covers both DXN `map` and `array`
        // (see the project's own collections decision) -- one branch,
        // key-preserving, handles both.
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::strip($v);
            }
            return $result;
        }
        if ($value instanceof DXNList) {
            return new DXNList(array_map(self::strip(...), $value->items));
        }
        if ($value instanceof DXNTuple) {
            return new DXNTuple(array_map(self::strip(...), $value->items));
        }
        if ($value instanceof DXNOrderedMap) {
            return new DXNOrderedMap(array_map(
                static fn (array $p): array => [self::strip($p[0]), self::strip($p[1])],
                $value->pairs,
            ));
        }
        if ($value instanceof DXNSet) {
            return new DXNSet(array_map(self::strip(...), $value->items));
        }
        if ($value instanceof DXNSortedSet) {
            return DXNSortedSet::fromArray(array_map(self::strip(...), $value->items));
        }
        if ($value instanceof DXNStruct) {
            return $value->isKeyed()
                ? DXNStruct::keyed($value->name, array_map(
                    static fn (array $p): array => [$p[0], self::strip($p[1])],
                    $value->pairs(),
                ))
                : DXNStruct::positional($value->name, array_map(self::strip(...), $value->values()));
        }
        if ($value instanceof DXNCustomTag) {
            return new DXNCustomTag($value->name, self::strip($value->value));
        }
        return $value;
    }
}
