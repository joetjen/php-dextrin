<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Value\DXNBits;
use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
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
use JOetjen\Dextrin\Value\Equality;

/**
 * Internal, compiled representation of a `.dxns` type expression -- the
 * 12-form vocabulary (`any`, `primitive`, `reference`, `list-of`,
 * `set-of`, `tuple-of`, `map-of`, `enum`, `one-of`, `all-of`,
 * `nilable`, `refine`) {@see Compiler} turns a parsed `.dxns` value
 * into, and what {@see self::matches()} checks a decoded/to-be-encoded
 * value against. (The 13th `dxn/DXN.md` §4 form, `struct`, isn't a
 * distinct compiled shape here either -- a `%schema{}` entry compiles
 * straight to {@see Compiled}, never to a `TypeExpr` a *field* could
 * hold; `reference` is what a field uses to point at one.)
 *
 * This vocabulary is fixed, not extensible from outside an actual
 * library change -- what schema *authors* extend instead is composing
 * these forms into a reusable named type, which needs no new form at
 * all.
 *
 * One field-per-form, all nullable except `form` itself, rather than a
 * class hierarchy -- mirrors the JS port's own plain tagged-object
 * representation (`{form: 'list-of', elem: ...}`) closely enough that
 * porting {@see Compiler} stays a mechanical
 * translation.
 */
final class TypeExpr
{
    private const PRIMITIVES = [
        'nil', 'boolean', 'integer', 'float', 'decimal', 'rational', 'string', 'char', 'symbol', 'keyword',
        'list', 'tuple', 'map', 'ordered-map', 'set', 'sorted-set', 'array',
        'date', 'time', 'timestamp', 'datetime', 'duration', 'uuid', 'uri', 'bytes', 'bits', 'regex',
    ];

    /**
     * @param list<self>|null $elems tuple-of
     * @param list<mixed>|null $literals enum
     * @param list<self>|null $variants one-of / all-of
     * @param array<string, mixed>|null $constraints refine
     */
    private function __construct(
        public readonly string $form,
        public readonly ?string $name = null,
        public readonly ?self $elem = null,
        public readonly ?array $elems = null,
        public readonly ?self $key = null,
        public readonly ?self $value = null,
        public readonly ?array $literals = null,
        public readonly ?array $variants = null,
        public readonly ?self $base = null,
        public readonly ?array $constraints = null,
    ) {
    }

    public static function any(): self
    {
        return new self('any');
    }

    public static function primitive(string $name): self
    {
        return new self('primitive', name: $name);
    }

    public static function reference(string $name): self
    {
        return new self('reference', name: $name);
    }

    public static function listOf(self $elem): self
    {
        return new self('list-of', elem: $elem);
    }

    public static function setOf(self $elem): self
    {
        return new self('set-of', elem: $elem);
    }

    /** @param list<self> $elems */
    public static function tupleOf(array $elems): self
    {
        return new self('tuple-of', elems: $elems);
    }

    public static function mapOf(self $key, self $value): self
    {
        return new self('map-of', key: $key, value: $value);
    }

    /** @param list<mixed> $literals */
    public static function enum(array $literals): self
    {
        return new self('enum', literals: $literals);
    }

    /** @param list<self> $variants */
    public static function oneOf(array $variants): self
    {
        return new self('one-of', variants: $variants);
    }

    /** @param list<self> $variants */
    public static function allOf(array $variants): self
    {
        return new self('all-of', variants: $variants);
    }

    public static function nilable(self $elem): self
    {
        return new self('nilable', elem: $elem);
    }

    /** @param array<string, mixed> $constraints */
    public static function refine(self $base, array $constraints): self
    {
        return new self('refine', base: $base, constraints: $constraints);
    }

    public static function isPrimitiveName(string $name): bool
    {
        return in_array($name, self::PRIMITIVES, true);
    }

    /**
     * Whether `$value` matches this type -- shared by both decode
     * (`Validator::materialize()`) and encode-time validation
     * (`Validator::validateForEncode()`): the same recursive check,
     * the same method, for both directions.
     *
     * `{form: 'reference', name}` checks the *originating schema's*
     * name, not the value's own shape -- which is how it correctly
     * rejects a materialized value that satisfies its own schema but
     * is the wrong one for this field. Two sources carry that name: an
     * *opaque*, unregistered `DXNStruct` carries its own wire name
     * directly; a value that went through a registered schema (decode
     * *or* encode-time validation alike) carries it via {@see
     * Validated}. The third, encode-only source: `$registry` (`null`
     * for decode, which never needs it) lets a *real, unwrapped*
     * application class instance's own class be checked against
     * whatever class was registered for `name` -- needed only for a
     * value that turned out to have no schema of its own to be wrapped
     * by.
     */
    public function matches(mixed $value, ?Registry $registry = null): bool
    {
        if ($this->form === 'any') {
            return true;
        }
        if ($this->form === 'reference') {
            return self::matchesReference($this->name ?? '', $value, $registry);
        }
        if ($value instanceof Validated) {
            return $this->matches($value->value, $registry);
        }
        return match ($this->form) {
            'primitive' => self::primitiveMatches($this->name ?? '', $value),
            'list-of' => $value instanceof DXNList && self::allMatch($this->elem, $value->items, $registry),
            'set-of' => $value instanceof DXNSet && self::allMatch($this->elem, $value->items, $registry),
            'tuple-of' => $this->tupleMatches($value, $registry),
            'map-of' => is_array($value) && $this->mapMatches($value, $registry),
            'enum' => $this->enumMatches($value),
            'one-of' => $this->anyVariantMatches($value, $registry),
            'all-of' => $this->everyVariantMatches($value, $registry),
            'nilable' => $value === null || (($this->elem?->matches($value, $registry)) ?? false),
            'refine' => (($this->base?->matches($value, $registry)) ?? false)
                && self::satisfiesConstraints($value, $this->constraints ?? []),
            default => false,
        };
    }

    private static function matchesReference(string $name, mixed $value, ?Registry $registry): bool
    {
        if ($value instanceof DXNStruct) {
            return $value->name === $name;
        }
        if ($value instanceof Validated) {
            return $value->name === $name;
        }
        // A struct is matched only by a struct (DXN.md §4.7): past a
        // struct or checked value of the right name, only the class
        // registered for `$name` is one. An array, or any other value, is
        // not a struct, however much it looks like one -- nothing is
        // converted to make it match.
        if ($registry !== null && is_object($value)) {
            $registered = $registry->fetchStructModule($name);
            if ($registered !== null) {
                return $value::class === $registered;
            }
        }
        return false;
    }

    // ---- primitives (dxn/DXN.md §1.3's 28 non-header types) ----------------

    private static function primitiveMatches(string $name, mixed $value): bool
    {
        return match ($name) {
            'nil' => $value === null,
            'boolean' => is_bool($value),
            'integer' => is_int($value) || $value instanceof DXNInteger,
            'float' => is_float($value),
            'decimal' => $value instanceof DXNDecimal,
            'rational' => $value instanceof DXNRational,
            'string' => is_string($value),
            'char' => $value instanceof DXNChar,
            'symbol' => $value instanceof DXNSymbol,
            'keyword' => $value instanceof DXNKeyword,
            'list' => $value instanceof DXNList,
            'tuple' => $value instanceof DXNTuple,
            // Both `map` and `array` decode/encode as a bare PHP array
            // (see the project's collections decision); `array_is_list`
            // is the only structural signal separating them, same as
            // Equality::kindOf()'s own dispatch -- an empty array is
            // indistinguishable and reads as `array`, not `map`.
            'map' => is_array($value) && !array_is_list($value),
            'ordered-map' => $value instanceof DXNOrderedMap,
            'set' => $value instanceof DXNSet,
            'sorted-set' => $value instanceof DXNSortedSet,
            'array' => is_array($value) && array_is_list($value),
            'date' => $value instanceof DXNDate,
            'time' => $value instanceof DXNTime,
            'timestamp' => $value instanceof DXNTimestamp,
            'datetime' => $value instanceof DXNDateTime || $value instanceof \DateTimeInterface,
            'duration' => $value instanceof DXNDuration,
            'uuid' => $value instanceof DXNUuid,
            'uri' => $value instanceof DXNUri,
            'bytes' => $value instanceof DXNBytes,
            // Bits and bytes are one value space (DXN.md §4.1): `:bits` takes both.
            'bits' => $value instanceof DXNBits || $value instanceof DXNBytes,
            'regex' => $value instanceof DXNRegex,
            default => false,
        };
    }

    // ---- compound forms ------------------------------------------------------

    /** @param list<mixed> $items */
    private static function allMatch(?self $elem, array $items, ?Registry $registry): bool
    {
        $type = $elem ?? throw new \LogicException('unreachable: list-of/set-of type expr missing elem');
        foreach ($items as $item) {
            if (!$type->matches($item, $registry)) {
                return false;
            }
        }
        return true;
    }

    private function tupleMatches(mixed $value, ?Registry $registry): bool
    {
        if (!$value instanceof DXNTuple) {
            return false;
        }
        $elems = $this->elems ?? throw new \LogicException('unreachable: tuple-of type expr missing elems');
        if (count($elems) !== count($value->items)) {
            return false;
        }
        foreach ($elems as $i => $elemType) {
            if (!$elemType->matches($value->items[$i], $registry)) {
                return false;
            }
        }
        return true;
    }

    /**
     * A DXN map/ordered-map key can be any DXN value, but a bare PHP
     * array's own key is always `int|string` (this port's own map-key
     * coercion, see `MapBuilder`'s doc) -- `map-of`'s own `key:` type
     * is therefore checked against that coerced PHP key directly,
     * never the original pre-coercion DXN key.
     *
     * @param array<int|string, mixed> $value
     */
    private function mapMatches(array $value, ?Registry $registry): bool
    {
        $keyType = $this->key ?? throw new \LogicException('unreachable: map-of type expr missing key');
        $valueType = $this->value ?? throw new \LogicException('unreachable: map-of type expr missing value');
        foreach ($value as $k => $v) {
            if (!$keyType->matches($k, $registry) || !$valueType->matches($v, $registry)) {
                return false;
            }
        }
        return true;
    }

    private function enumMatches(mixed $value): bool
    {
        foreach ($this->literals ?? [] as $literal) {
            if (Equality::dxnEquals($literal, $value)) {
                return true;
            }
        }
        return false;
    }

    private function anyVariantMatches(mixed $value, ?Registry $registry): bool
    {
        foreach ($this->variants ?? [] as $variant) {
            if ($variant->matches($value, $registry)) {
                return true;
            }
        }
        return false;
    }

    private function everyVariantMatches(mixed $value, ?Registry $registry): bool
    {
        foreach ($this->variants ?? [] as $variant) {
            if (!$variant->matches($value, $registry)) {
                return false;
            }
        }
        return true;
    }

    // ---- refine constraints ---------------------------------------------------

    /** @param array<string, mixed> $constraints */
    private static function satisfiesConstraints(mixed $value, array $constraints): bool
    {
        foreach ($constraints as $key => $arg) {
            if (!self::satisfiesConstraint($key, $arg, $value)) {
                return false;
            }
        }
        return true;
    }

    private static function satisfiesConstraint(string $key, mixed $arg, mixed $value): bool
    {
        return match ($key) {
            'min' => self::toComparableFloat($value) >= self::toComparableFloat($arg),
            'max' => self::toComparableFloat($value) <= self::toComparableFloat($arg),
            'exclusive-min' => self::toComparableFloat($value) > self::toComparableFloat($arg),
            'exclusive-max' => self::toComparableFloat($value) < self::toComparableFloat($arg),
            'multiple-of' => self::isMultipleOf(self::toComparableFloat($value), self::toComparableFloat($arg)),
            'min-length' => is_string($value) && mb_strlen($value, 'UTF-8') >= self::toIntArg($arg),
            'max-length' => is_string($value) && mb_strlen($value, 'UTF-8') <= self::toIntArg($arg),
            'pattern' => is_string($value) && $arg instanceof DXNRegex && preg_match($arg->toPcrePattern(), $value) === 1,
            'min-count' => self::countOf($value) >= self::toIntArg($arg),
            'max-count' => self::countOf($value) <= self::toIntArg($arg),
            default => false,
        };
    }

    private static function countOf(mixed $value): int
    {
        return match (true) {
            $value instanceof DXNList, $value instanceof DXNTuple => count($value->items),
            $value instanceof DXNSet, $value instanceof DXNSortedSet => count($value->items),
            default => throw new \InvalidArgumentException(
                'min-count/max-count requires a list/tuple/set/sorted-set value, got ' . Equality::kindOf($value),
            ),
        };
    }

    private static function isMultipleOf(float $a, float $b): bool
    {
        return fmod($a, $b) === 0.0;
    }

    private static function toComparableFloat(mixed $value): float
    {
        return match (true) {
            is_int($value) => (float) $value,
            $value instanceof DXNInteger => (float) $value->toString(),
            is_float($value) => $value,
            $value instanceof DXNDecimal => $value->toFloat(),
            $value instanceof DXNRational => $value->toFloat(),
            default => throw new \InvalidArgumentException(
                'cannot compare a non-numeric value in a refine constraint: ' . Equality::kindOf($value),
            ),
        };
    }

    private static function toIntArg(mixed $arg): int
    {
        if (is_int($arg)) {
            return $arg;
        }
        if ($arg instanceof DXNInteger && $arg->fitsInInt()) {
            return $arg->toInt();
        }
        throw new \InvalidArgumentException('expected an integer constraint argument, got ' . Equality::kindOf($arg));
    }
}
