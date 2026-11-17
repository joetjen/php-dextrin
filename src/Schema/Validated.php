<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNMap;
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
 *
 * `also` names the schemas the value passes as besides its own: those its
 * schema extends, and that it is a valid one of (DXNS.md §4b).
 *
 * `version` is the version it was read or checked with, and `asRead` the
 * struct as that version reads it, before any materializer: what a field
 * pinned to that version takes instead (DXNS.md §5c). `refused` is why it
 * has no value -- an older version with no materializer to upgrade it --
 * which stands unless a pinned field takes `asRead`.
 */
final class Validated
{
    /** @param list<string> $also */
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
        public readonly array $also = [],
        public readonly ?int $version = null,
        public readonly ?DXNStruct $asRead = null,
        public readonly ?DXNError $refused = null,
    ) {
    }

    /** This wrapper holding `$value` instead, and otherwise unchanged. */
    public function withValue(mixed $value): self
    {
        return new self($this->name, $value, $this->also, $this->version, $this->asRead, $this->refused);
    }

    /**
     * `$value` with every wrapper removed, as {@see strip()} does -- or
     * the first refusal one still holds is thrown: a struct no field took
     * as read, and that could not be upgraded.
     *
     * @throws DXNError the refusal
     */
    public static function unwrap(mixed $value): mixed
    {
        $refused = self::refusal($value);
        if ($refused !== null) {
            throw $refused;
        }
        return self::strip($value);
    }

    /** The first refusal a wrapper in `$value` holds, however deep. */
    private static function refusal(mixed $value): ?DXNError
    {
        if ($value instanceof self) {
            return $value->refused ?? self::refusal($value->value);
        }
        $children = match (true) {
            is_array($value) => [...array_keys($value), ...array_values($value)],
            $value instanceof DXNList, $value instanceof DXNTuple, $value instanceof DXNSet, $value instanceof DXNSortedSet => $value->items,
            $value instanceof DXNOrderedMap => array_merge([], ...$value->pairs),
            $value instanceof DXNMap => array_merge([], ...$value->pairs()),
            $value instanceof DXNStruct => [
                ...($value->isKeyed() ? array_map(static fn (array $p): mixed => $p[1], $value->pairs()) : $value->values()),
                ...array_merge([], ...$value->extra),
            ],
            $value instanceof DXNCustomTag => [$value->value],
            default => [],
        };
        foreach ($children as $child) {
            $found = self::refusal($child);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
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
                ? $value->withPairs(array_map(
                    static fn (array $p): array => [$p[0], self::strip($p[1])],
                    $value->pairs(),
                ))
                : $value->withValues(array_map(self::strip(...), $value->values()));
        }
        if ($value instanceof DXNCustomTag) {
            return new DXNCustomTag($value->name, self::strip($value->value));
        }
        return $value;
    }
}
