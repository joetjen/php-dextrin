<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNTimestamp;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUri;
use JOetjen\Dextrin\Value\DXNUuid;

/**
 * Schema-driven type coercion on encode -- a capability with no Elixir
 * `dextrin` equivalent, ported from `node-dextrin` because the value
 * model's own `DXN*::fromX()`/`::from*()` conversion helpers already
 * make the coercion table trivial to build: every class's documented
 * native-type converter doubles as an encode-time coercion, with no
 * separate registration.
 *
 * {@see self::tryCoerce()} is attempted by {@see Validator} only when a
 * value doesn't already match its field's declared type -- never
 * during decode, which always knows its own concrete wire type
 * unambiguously and has nothing to coerce. Governed by
 * `Dextrin::encode()`/`encodeBinary()`'s `$coerce` parameter, default
 * `true`.
 *
 * One coercion the JS port has that this port deliberately omits:
 * `symbol` from a native `symbol`. PHP's `keyword` always decodes as
 * {@see \JOetjen\Dextrin\Value\DXNKeyword}, with nothing analogous to
 * JS's `Symbol.for`, so there is no native PHP value that source
 * coercion could ever apply to.
 */
final class Coercion
{
    private function __construct()
    {
    }

    /**
     * Attempts to coerce `$value` toward `$type`. Returns the coerced
     * value on success, or {@see NotCoercible::Instance} if no
     * applicable coercion exists (the caller falls back to treating
     * this as an ordinary type mismatch). Never throws -- a coercion
     * attempt that fails (e.g. an invalid UUID string) is simply not a
     * coercion, not a distinct error.
     */
    public static function tryCoerce(TypeExpr $type, mixed $value): mixed
    {
        return match ($type->form) {
            'primitive' => self::coercePrimitive($type->name ?? '', $value),
            'list-of' => self::coerceListOf($type->elem, $value),
            'set-of' => self::coerceSetOf($type->elem, $value),
            'tuple-of' => self::coerceTupleOf($type->elems ?? [], $value),
            'nilable' => $value === null ? NotCoercible::Instance : self::tryCoerce($type->elem ?? TypeExpr::any(), $value),
            'one-of' => self::coerceOneOf($type->variants ?? [], $value),
            'refine' => self::tryCoerce($type->base ?? TypeExpr::any(), $value),
            default => NotCoercible::Instance,
        };
    }

    private static function coercePrimitive(string $name, mixed $value): mixed
    {
        return match ($name) {
            'rational' => is_float($value) ? DXNRational::fromFloat($value) : NotCoercible::Instance,
            'decimal' => is_float($value) ? DXNDecimal::fromFloat($value) : NotCoercible::Instance,
            'char' => is_string($value) ? self::tryOr(static fn (): DXNChar => DXNChar::fromString($value)) : NotCoercible::Instance,
            'list' => is_array($value) && array_is_list($value) ? DXNList::fromArray($value) : NotCoercible::Instance,
            'tuple' => is_array($value) && array_is_list($value) ? DXNTuple::fromArray($value) : NotCoercible::Instance,
            'ordered-map' => is_array($value) ? DXNOrderedMap::fromArray($value) : NotCoercible::Instance,
            'sorted-set' => is_array($value) ? DXNSortedSet::fromArray($value) : ($value instanceof DXNSet ? DXNSortedSet::fromSet($value) : NotCoercible::Instance),
            'array' => $value instanceof DXNList ? $value->toArray() : NotCoercible::Instance,
            'date' => $value instanceof \DateTimeImmutable ? self::tryOr(static fn (): DXNDate => DXNDate::fromDateTimeImmutable($value)) : NotCoercible::Instance,
            'timestamp' => $value instanceof \DateTimeImmutable ? DXNTimestamp::fromDateTimeImmutable($value) : NotCoercible::Instance,
            'datetime' => $value instanceof \DateTimeImmutable ? DXNDateTime::fromDateTimeImmutable($value) : NotCoercible::Instance,
            'uuid' => is_string($value) ? self::tryOr(static fn (): DXNUuid => DXNUuid::fromString($value)) : NotCoercible::Instance,
            'uri' => is_string($value) ? DXNUri::fromString($value) : NotCoercible::Instance,
            'regex' => is_string($value) ? self::tryOr(static fn (): DXNRegex => DXNRegex::fromPcrePattern($value)) : NotCoercible::Instance,
            default => NotCoercible::Instance,
        };
    }

    private static function coerceListOf(?TypeExpr $elemType, mixed $value): mixed
    {
        if (!is_array($value) || !array_is_list($value)) {
            return NotCoercible::Instance;
        }
        $elem = $elemType ?? throw new \LogicException('unreachable: list-of type expr missing elem');
        return DXNList::fromArray(array_map(static fn (mixed $v): mixed => self::coerceOrOriginal($elem, $v), $value));
    }

    private static function coerceSetOf(?TypeExpr $elemType, mixed $value): mixed
    {
        $elem = $elemType ?? throw new \LogicException('unreachable: set-of type expr missing elem');
        if (is_array($value) && array_is_list($value)) {
            return new DXNSet(array_map(static fn (mixed $v): mixed => self::coerceOrOriginal($elem, $v), $value));
        }
        if ($value instanceof DXNSet) {
            return new DXNSet(array_map(static fn (mixed $v): mixed => self::coerceOrOriginal($elem, $v), $value->items));
        }
        return NotCoercible::Instance;
    }

    /** @param list<TypeExpr> $elemTypes */
    private static function coerceTupleOf(array $elemTypes, mixed $value): mixed
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== count($elemTypes)) {
            return NotCoercible::Instance;
        }
        $coerced = [];
        foreach ($value as $i => $v) {
            $coerced[] = self::coerceOrOriginal($elemTypes[$i], $v);
        }
        return DXNTuple::fromArray($coerced);
    }

    /** @param list<TypeExpr> $variants */
    private static function coerceOneOf(array $variants, mixed $value): mixed
    {
        foreach ($variants as $variant) {
            $coerced = self::tryCoerce($variant, $value);
            if (!$coerced instanceof NotCoercible) {
                return $coerced;
            }
        }
        return NotCoercible::Instance;
    }

    /**
     * Returns the coerced value, or `$value` itself unchanged if no
     * coercion applies -- used for element-wise coercion, where a
     * non-coercible element should still pass through for the outer
     * type-check to judge on its own merits.
     */
    private static function coerceOrOriginal(TypeExpr $type, mixed $value): mixed
    {
        $coerced = self::tryCoerce($type, $value);
        return $coerced instanceof NotCoercible ? $value : $coerced;
    }

    /** @param \Closure(): mixed $fn */
    private static function tryOr(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return NotCoercible::Instance;
        }
    }
}
