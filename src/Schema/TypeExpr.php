<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Text\Temporal;
use JOetjen\Dextrin\Value\DXNBits;
use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNDuration;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNIp;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNLocalDateTime;
use JOetjen\Dextrin\Value\DXNMap;
use JOetjen\Dextrin\Value\DXNObjectId;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTime;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUri;
use JOetjen\Dextrin\Value\DXNUuid;
use JOetjen\Dextrin\Value\Equality;

/**
 * Internal, compiled representation of a `.dxns` type expression -- the
 * vocabulary of DXN.md §4.1 (`any`, `never`, `primitive`, `reference`,
 * `list-of`, `list-items`, `set-of`, `tuple-of`, `tuple-of-all`, `map-of`, `array-of`,
 * `ordered-map-of`, `sorted-set-of`, `enum`, `one-of`, `any-of`,
 * `all-of`, `not`, `nilable`, `refine`) {@see Compiler} turns a parsed `.dxns` value
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
        'date', 'time', 'datetime', 'local-datetime', 'duration', 'uuid', 'objectid', 'ip', 'uri', 'bytes', 'bits', 'regex',
    ];

    /**
     * @param list<self>|null $elems tuple-of; a use's arguments (apply)
     * @param list<mixed>|null $literals enum
     * @param list<self>|null $variants one-of / any-of / all-of
     * @param array<string, mixed>|null $constraints refine
     * @param list<Field>|null $fields shape
     * @param list<array{0: mixed, 1: self}>|null $branches case
     * @param list<string>|null $params generic
     * @param list<Particle>|null $particles list-items
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
        public readonly ?array $fields = null,
        public readonly ?array $branches = null,
        public readonly ?self $otherwise = null,
        public readonly ?array $params = null,
        public readonly ?array $particles = null,
    ) {
    }

    /**
     * `%shape{...}` (design/shapes-and-case.md): a plain map with these
     * fields, its missing defaults filled.
     *
     * @param list<Field> $fields
     */
    public static function shape(?string $name, array $fields): self
    {
        return new self('shape', name: $name, fields: $fields);
    }

    /**
     * `{:case field %{value => type, :else => type}}`: the type the
     * sibling `field`'s value chooses.
     *
     * @param list<array{0: mixed, 1: self}> $branches
     */
    public static function case(string $field, array $branches, ?self $otherwise): self
    {
        return new self('case', name: $field, branches: $branches, otherwise: $otherwise);
    }

    /** A recursive named type's reference to itself, looked up when it is checked. */
    public static function named(string $name): self
    {
        return new self('named', name: $name);
    }

    /** @param list<string> $params */
    public static function generic(array $params, self $body): self
    {
        return new self('generic', base: $body, params: $params);
    }

    /** @param list<self> $args */
    public static function apply(string $name, array $args): self
    {
        return new self('apply', name: $name, elems: $args);
    }

    public static function param(string $name): self
    {
        return new self('param', name: $name);
    }

    /**
     * This type with each part replaced, for the compiler's resolution.
     *
     * @param array<string, mixed> $parts
     */
    public function with(array $parts): self
    {
        $all = get_object_vars($this);
        foreach ($parts as $k => $v) {
            $all[$k] = $v;
        }
        return new self(...$all);
    }

    public static function any(): self
    {
        return new self('any');
    }

    public static function never(): self
    {
        return new self('never');
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

    /**
     * `{:list-items p ...}`: a list's items in order (DXN.md §4.1a).
     *
     * @param list<Particle> $particles
     */
    public static function listItems(array $particles): self
    {
        return new self('list-items', particles: $particles);
    }

    /** `{:tuple-of-all elem}`: a tuple of any length, every item an `$elem`. */
    public static function tupleOfAll(self $elem): self
    {
        return new self('tuple-of-all', elem: $elem);
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

    public static function arrayOf(self $elem): self
    {
        return new self('array-of', elem: $elem);
    }

    public static function orderedMapOf(self $key, self $value): self
    {
        return new self('ordered-map-of', key: $key, value: $value);
    }

    public static function sortedSetOf(self $elem): self
    {
        return new self('sorted-set-of', elem: $elem);
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
    public static function anyOf(array $variants): self
    {
        return new self('any-of', variants: $variants);
    }

    public static function not(self $elem): self
    {
        return new self('not', elem: $elem);
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
     * the same method, for both directions. It is {@see self::conform()}
     * answered as a boolean, so there is one check, not two.
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
        return $this->conform($value, new Context($registry)) !== NoMatch::Instance;
    }

    /**
     * `$value` checked against this type in `$ctx`: the value with every
     * shape's missing defaults filled in (design/shapes-and-case.md, Jan,
     * 2026-10-20), or {@see NoMatch}. Nothing but a shape changes a value.
     */
    public function conform(mixed $value, Context $ctx): mixed
    {
        switch ($this->form) {
            case 'any':
                return $value;
            case 'never':
            case 'param':
            case 'generic':
                return NoMatch::Instance;
            case 'reference':
                return self::matchesReference($this->name ?? '', $value, $ctx->registry) ? $value : NoMatch::Instance;
            case 'case':
                // A field's type is chosen by its sibling's value, wherever
                // in the field's type the case stands.
                $chosen = $this->chosen($ctx->siblings);
                return $chosen === null ? NoMatch::Instance : $chosen->conform($value, $ctx);
            case 'named':
                // A recursive named type's reference to itself (DXN.md
                // §4.5); with no registry there is nothing to look in.
                $named = $ctx->registry?->fetchTypeAlias($this->name ?? '');
                return $named instanceof self ? $named->conform($value, $ctx) : NoMatch::Instance;
            case 'apply':
                // One use of a generic: its body, the arguments standing
                // where its parameters stand.
                $generic = $ctx->registry?->fetchTypeAlias($this->name ?? '');
                $args = $this->elems ?? [];
                if (!$generic instanceof self || $generic->form !== 'generic' || count($generic->params ?? []) !== count($args)) {
                    return NoMatch::Instance;
                }
                /** @var self $body */
                $body = $generic->base;
                return $body->substitute(array_combine($generic->params ?? [], $args))->conform($value, $ctx);
        }

        if ($value instanceof Validated) {
            $inner = $this->conform($value->value, $ctx);
            return $inner === NoMatch::Instance ? NoMatch::Instance : new Validated($value->name, $inner);
        }

        switch ($this->form) {
            case 'primitive':
                return self::primitiveMatches($this->name ?? '', $value) ? $value : NoMatch::Instance;
            case 'shape':
                return is_array($value) || $value instanceof DXNMap ? $this->conformShape($value, $ctx) : NoMatch::Instance;
            case 'list-of':
                if (!$value instanceof DXNList) {
                    return NoMatch::Instance;
                }
                $items = self::all($this->elem, $value->items, $ctx);
                return $items === NoMatch::Instance ? NoMatch::Instance : new DXNList($items);
            case 'list-items':
                if (!$value instanceof DXNList) {
                    return NoMatch::Instance;
                }
                $count = count($value->items);
                $items = self::items($this->particles ?? [], 0, $value->items, 0, $ctx, static fn (int $at): array|NoMatch => self::allTaken($at, $count));
                return $items === NoMatch::Instance ? NoMatch::Instance : new DXNList($items);
            case 'set-of':
                if (!$value instanceof DXNSet) {
                    return NoMatch::Instance;
                }
                return self::all($this->elem, $value->items, $ctx) === NoMatch::Instance ? NoMatch::Instance : $value;
            case 'tuple-of':
                return $this->conformTuple($value, $ctx);
            case 'tuple-of-all':
                if (!$value instanceof DXNTuple) {
                    return NoMatch::Instance;
                }
                $items = self::all($this->elem, $value->items, $ctx);
                return $items === NoMatch::Instance ? NoMatch::Instance : new DXNTuple($items);
            case 'map-of':
                return is_array($value) ? $this->conformMap($value, $ctx) : NoMatch::Instance;
            case 'array-of':
                if (!is_array($value) || !array_is_list($value)) {
                    return NoMatch::Instance;
                }
                return self::all($this->elem, $value, $ctx);
            case 'ordered-map-of':
                if (!$value instanceof DXNOrderedMap) {
                    return NoMatch::Instance;
                }
                return $this->conformPairs($value->pairs, $ctx) === NoMatch::Instance ? NoMatch::Instance : $value;
            case 'sorted-set-of':
                if (!$value instanceof DXNSortedSet) {
                    return NoMatch::Instance;
                }
                return self::all($this->elem, $value->items, $ctx) === NoMatch::Instance ? NoMatch::Instance : $value;
            case 'enum':
                return $this->enumMatches($value) ? $value : NoMatch::Instance;
            case 'one-of':
                // Exactly one variant matches (JSON Schema's oneOf; Jan,
                // 2026-10-20): every variant is tried, stopping at a second.
                $found = NoMatch::Instance;
                foreach ($this->variants ?? [] as $variant) {
                    $c = $variant->conform($value, $ctx);
                    if ($c === NoMatch::Instance) {
                        continue;
                    }
                    if ($found !== NoMatch::Instance) {
                        return NoMatch::Instance;
                    }
                    $found = $c;
                }
                return $found;
            case 'any-of':
                // One or more match (JSON Schema's anyOf): the first that does.
                foreach ($this->variants ?? [] as $variant) {
                    $c = $variant->conform($value, $ctx);
                    if ($c !== NoMatch::Instance) {
                        return $c;
                    }
                }
                return NoMatch::Instance;
            case 'not':
                return ($this->elem?->conform($value, $ctx) ?? NoMatch::Instance) === NoMatch::Instance ? $value : NoMatch::Instance;
            case 'all-of':
                // Every variant, each taking what the one before it answered.
                $current = $value;
                foreach ($this->variants ?? [] as $variant) {
                    $current = $variant->conform($current, $ctx);
                    if ($current === NoMatch::Instance) {
                        return NoMatch::Instance;
                    }
                }
                return $current;
            case 'nilable':
                return $value === null ? null : ($this->elem?->conform($value, $ctx) ?? NoMatch::Instance);
            case 'refine':
                $c = $this->base?->conform($value, $ctx) ?? NoMatch::Instance;
                return $c !== NoMatch::Instance && self::satisfiesConstraints($c, $this->constraints ?? [], $ctx) ? $c : NoMatch::Instance;
            default:
                return NoMatch::Instance;
        }
    }

    /**
     * Every item conformed, in order, or {@see NoMatch} at the first that
     * is not.
     *
     * @param list<mixed> $items
     * @return list<mixed>|NoMatch
     */
    private static function all(?self $elem, array $items, Context $ctx): array|NoMatch
    {
        $type = $elem ?? throw new \LogicException('unreachable: collection type expr missing elem');
        $out = [];
        foreach ($items as $item) {
            $c = $type->conform($item, $ctx);
            if ($c === NoMatch::Instance) {
                return NoMatch::Instance;
            }
            $out[] = $c;
        }
        return $out;
    }

    // ---- list items -------------------------------------------------------------

    /**
     * `$particles` from the `$p`th matched against `$items` from the
     * `$at`th, then `$rest` against the position they leave: the items
     * conformed, in order, or {@see NoMatch}. A repeat takes as many
     * items as it can while the rest still matches, and gives back one at
     * a time until it does (DXN.md §4.1a).
     *
     * @param list<Particle> $particles
     * @param list<mixed> $items
     * @param \Closure(int): (list<mixed>|NoMatch) $rest
     * @return list<mixed>|NoMatch
     */
    private static function items(array $particles, int $p, array $items, int $at, Context $ctx, \Closure $rest): array|NoMatch
    {
        if ($p === count($particles)) {
            return $rest($at);
        }
        $particle = $particles[$p];
        if ($particle->type !== null) {
            if ($at === count($items)) {
                return NoMatch::Instance;
            }
            $conformed = $particle->type->conform($items[$at], $ctx);
            if ($conformed === NoMatch::Instance) {
                return NoMatch::Instance;
            }
            $tail = self::items($particles, $p + 1, $items, $at + 1, $ctx, $rest);
            return $tail === NoMatch::Instance ? NoMatch::Instance : [$conformed, ...$tail];
        }
        return self::repeat(
            $particle->group ?? [],
            $particle->min,
            $particle->max,
            $items,
            $at,
            $ctx,
            static fn (int $after): array|NoMatch => self::items($particles, $p + 1, $items, $after, $ctx, $rest),
        );
    }

    /**
     * One more repetition first, while `$most` allows it; stopping once
     * `$least` is reached. A repetition that took no item is the last:
     * every further one would take none either, so `$least` is met by them.
     *
     * @param list<Particle> $group
     * @param list<mixed> $items
     * @param \Closure(int): (list<mixed>|NoMatch) $rest
     * @return list<mixed>|NoMatch
     */
    private static function repeat(array $group, int $least, ?int $most, array $items, int $at, Context $ctx, \Closure $rest): array|NoMatch
    {
        $again = NoMatch::Instance;
        if ($most === null || $most > 0) {
            $again = self::items(
                $group,
                0,
                $items,
                $at,
                $ctx,
                static fn (int $afterOne): array|NoMatch => $afterOne === $at
                    ? $rest($afterOne)
                    : self::repeat($group, max($least - 1, 0), $most === null ? null : $most - 1, $items, $afterOne, $ctx, $rest),
            );
        }
        if ($again !== NoMatch::Instance) {
            return $again;
        }
        return $least === 0 ? $rest($at) : NoMatch::Instance;
    }

    /**
     * The end of a `{:list-items}`: every item taken.
     *
     * @return list<mixed>|NoMatch
     */
    private static function allTaken(int $at, int $count): array|NoMatch
    {
        return $at === $count ? [] : NoMatch::Instance;
    }

    private function conformTuple(mixed $value, Context $ctx): mixed
    {
        if (!$value instanceof DXNTuple) {
            return NoMatch::Instance;
        }
        $elems = $this->elems ?? [];
        if (count($elems) !== count($value->items)) {
            return NoMatch::Instance;
        }
        $items = [];
        foreach ($elems as $i => $elemType) {
            $c = $elemType->conform($value->items[$i], $ctx);
            if ($c === NoMatch::Instance) {
                return NoMatch::Instance;
            }
            $items[] = $c;
        }
        return new DXNTuple($items);
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
    private function conformMap(array $value, Context $ctx): mixed
    {
        $keyType = $this->key ?? throw new \LogicException('unreachable: map-of type expr missing key');
        $valueType = $this->value ?? throw new \LogicException('unreachable: map-of type expr missing value');
        $out = [];
        foreach ($value as $k => $v) {
            if ($keyType->conform($k, $ctx) === NoMatch::Instance) {
                return NoMatch::Instance;
            }
            $c = $valueType->conform($v, $ctx);
            if ($c === NoMatch::Instance) {
                return NoMatch::Instance;
            }
            $out[$k] = $c;
        }
        return $out;
    }

    /** @param list<array{0: mixed, 1: mixed}> $pairs */
    private function conformPairs(array $pairs, Context $ctx): mixed
    {
        $keyType = $this->key ?? throw new \LogicException('unreachable: ordered-map-of type expr missing key');
        $valueType = $this->value ?? throw new \LogicException('unreachable: ordered-map-of type expr missing value');
        foreach ($pairs as [$k, $v]) {
            if ($keyType->conform($k, $ctx) === NoMatch::Instance || $valueType->conform($v, $ctx) === NoMatch::Instance) {
                return NoMatch::Instance;
            }
        }
        return $pairs;
    }

    // ---- {:case} ----------------------------------------------------------------

    /**
     * The type the branches give the sibling field's value; the `:else`
     * (`null` without one) when none is equal, or the sibling is absent.
     *
     * @param array<string, mixed>|null $siblings
     */
    private function chosen(?array $siblings): ?self
    {
        $field = $this->name ?? '';
        if ($siblings === null || !array_key_exists($field, $siblings)) {
            return $this->otherwise;
        }
        $sibling = Validated::strip($siblings[$field]);
        foreach ($this->branches ?? [] as [$literal, $type]) {
            if (Equality::dxnEquals($literal, $sibling)) {
                return $type;
            }
        }
        return $this->otherwise;
    }

    // ---- %shape{} -----------------------------------------------------------------

    /**
     * A map against a shape's fields: each declared field matched by name,
     * whatever spelling its key has (`name:`, `"name" =>`, `:"name" =>`)
     * and refused given twice; a missing field takes its default, under a
     * keyword key; an extra key is kept, and refused by a strict decode --
     * as a struct's fields are (design/shapes-and-case.md).
     *
     * @param array<int|string, mixed>|DXNMap $map
     */
    private function conformShape(array|DXNMap $map, Context $ctx): mixed
    {
        $pairs = $map instanceof DXNMap
            ? $map->pairs()
            : array_map(static fn (int|string $k, mixed $v): array => [MapBuilder::toDxnMapKey($k), $v], array_keys($map), array_values($map));
        $named = [];
        foreach ($pairs as $i => [$key, $value]) {
            $name = self::keyName($key);
            if ($name === null) {
                continue;
            }
            if (array_key_exists($name, $named)) {
                return NoMatch::Instance;
            }
            $named[$name] = [$i, $value];
        }
        $inner = new Context($ctx->registry, array_map(static fn (array $iv): mixed => $iv[1], $named));
        /** @var array<int, mixed> $replaced pair index => conformed value */
        $replaced = [];
        /** @var list<array{0: mixed, 1: mixed}> $added */
        $added = [];
        foreach ($this->fields ?? [] as $field) {
            if (array_key_exists($field->name, $named)) {
                [$i, $given] = $named[$field->name];
                $c = $field->type->conform($given, $inner);
                if ($c === NoMatch::Instance) {
                    return NoMatch::Instance;
                }
                $replaced[$i] = $c;
            } elseif ($field->default !== Field::NO_DEFAULT) {
                $added[] = [new DXNKeyword($field->name), $field->default];
            } elseif ($field->required) {
                return NoMatch::Instance;
            }
        }
        $out = [];
        foreach ($pairs as $i => $pair) {
            $out[] = [$pair[0], array_key_exists($i, $replaced) ? $replaced[$i] : $pair[1]];
        }
        $pairs = [...$out, ...$added];
        if ($ctx->registry !== null && $ctx->registry->isStrict()) {
            $declared = array_map(static fn (Field $f): string => $f->name, $this->fields ?? []);
            foreach ($pairs as [$key]) {
                $name = self::keyName($key);
                if ($name === null || !in_array($name, $declared, true)) {
                    return NoMatch::Instance;
                }
            }
        }
        return $map instanceof DXNMap ? new DXNMap($pairs) : MapBuilder::build($pairs, static fn (string $m): \Throwable => new \LogicException($m));
    }

    /** The name a map key gives a field, or `null`. */
    private static function keyName(mixed $key): ?string
    {
        return match (true) {
            $key instanceof DXNKeyword => $key->name,
            is_string($key) => $key,
            default => null,
        };
    }

    // ---- generics -----------------------------------------------------------------

    /**
     * This type with each parameter `$bound` names replaced by its argument.
     *
     * @param array<string, self> $bound
     */
    public function substitute(array $bound): self
    {
        $sub = static fn (?self $t): ?self => $t?->substitute($bound);
        $subAll = static fn (?array $ts): ?array => $ts === null ? null : array_map(static fn (self $t): self => $t->substitute($bound), $ts);
        return match ($this->form) {
            'param' => $bound[$this->name ?? ''] ?? $this,
            'apply', 'tuple-of' => $this->with(['elems' => $subAll($this->elems)]),
            'shape' => $this->with(['fields' => array_map(
                static fn (Field $f): Field => new Field($f->name, $f->required, $f->type->substitute($bound), $f->default, $f->description),
                $this->fields ?? [],
            )]),
            'case' => $this->with([
                'branches' => array_map(static fn (array $b): array => [$b[0], $b[1]->substitute($bound)], $this->branches ?? []),
                'otherwise' => $sub($this->otherwise),
            ]),
            'refine' => $this->with(['base' => $sub($this->base), 'constraints' => self::withContains(
                $this->constraints ?? [],
                static fn (self $t): self => $t->substitute($bound),
            )]),
            'list-items' => $this->with(['particles' => array_map(
                static fn (Particle $p): Particle => $p->map(static fn (self $t): self => $t->substitute($bound)),
                $this->particles ?? [],
            )]),
            'one-of', 'any-of', 'all-of' => $this->with(['variants' => $subAll($this->variants)]),
            'list-of', 'set-of', 'array-of', 'sorted-set-of', 'tuple-of-all', 'not', 'nilable' => $this->with(['elem' => $sub($this->elem)]),
            'map-of', 'ordered-map-of' => $this->with(['key' => $sub($this->key), 'value' => $sub($this->value)]),
            default => $this,
        };
    }

    /**
     * A refine's `$constraints` with its `contains` type, if it has one,
     * replaced by what `$map` makes of it.
     *
     * @param array<string, mixed> $constraints
     * @param \Closure(self): self $map
     * @return array<string, mixed>
     */
    public static function withContains(array $constraints, \Closure $map): array
    {
        if (($constraints['contains'] ?? null) instanceof self) {
            $constraints['contains'] = $map($constraints['contains']);
        }
        return $constraints;
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
            // A datetime is one type whatever its offset, UTC included
            // (Jan, 2026-10-20); `:timestamp` is a refinement of it
            // ({@see Compiler}).
            'datetime' => $value instanceof DXNDateTime || $value instanceof \DateTimeInterface,
            'local-datetime' => $value instanceof DXNLocalDateTime,
            'duration' => $value instanceof DXNDuration,
            'uuid' => $value instanceof DXNUuid,
            'objectid' => $value instanceof DXNObjectId,
            'ip' => $value instanceof DXNIp,
            'uri' => $value instanceof DXNUri,
            'bytes' => $value instanceof DXNBytes,
            // Bits and bytes are one value space (DXN.md §4.1): `:bits` takes both.
            'bits' => $value instanceof DXNBits || $value instanceof DXNBytes,
            'regex' => $value instanceof DXNRegex,
            default => false,
        };
    }

    // ---- compound forms ------------------------------------------------------

    private function enumMatches(mixed $value): bool
    {
        foreach ($this->literals ?? [] as $literal) {
            if (Equality::dxnEquals($literal, $value)) {
                return true;
            }
        }
        return false;
    }

    // ---- refine constraints ---------------------------------------------------

    /** @param array<string, mixed> $constraints */
    private static function satisfiesConstraints(mixed $value, array $constraints, Context $ctx): bool
    {
        foreach ($constraints as $key => $arg) {
            $satisfied = $key === 'contains'
                ? $arg instanceof self && self::contains($arg, $constraints, $value, $ctx)
                : self::satisfiesConstraint($key, $arg, $value);
            if (!$satisfied) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether between `min-contains` (1 when not given) and `max-contains`
     * of the collection's items are a `$type`.
     *
     * @param array<string, mixed> $constraints
     */
    private static function contains(self $type, array $constraints, mixed $value, Context $ctx): bool
    {
        $items = self::itemsOf($value);
        if ($items === null) {
            return false;
        }
        $count = 0;
        foreach ($items as $item) {
            if ($type->conform($item, $ctx) !== NoMatch::Instance) {
                $count++;
            }
        }
        $most = $constraints['max-contains'] ?? null;
        return $count >= self::toIntArg($constraints['min-contains'] ?? 1)
            && ($most === null || $count <= self::toIntArg($most));
    }

    /**
     * One refine key against `$value` (DXN.md §4.2). NaN lies outside
     * every range, as every IEEE 754 comparison with it is false, which
     * PHP's own float comparisons already answer; the infinities compare
     * as the numbers they are (Jan, 2026-10-20).
     */
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
            // Counted with `contains`, which reads them.
            'min-contains', 'max-contains' => true,
            'unique' => match ($arg) {
                false => true,
                true => self::distinct(self::itemsOf($value), static fn (mixed $item): array => [self::identity($item)]),
                default => false,
            },
            // Items without the field are compared with none.
            'unique-by' => is_string($arg) && self::distinct(self::itemsOf($value), static fn (mixed $item): array => self::fieldIdentity($item, $arg)),
            // `finite: true` refuses NaN and both infinities.
            'finite' => $arg !== true || !is_float($value) || is_finite($value),
            'offsets' => self::hasOffsetIn($value, $arg),
            // An ip's version, 4 or 6, and whether it is a prefix.
            'version' => $value instanceof DXNIp && $value->version() === $arg,
            'prefix' => $value instanceof DXNIp && ($value->prefix !== null) === $arg,
            default => false,
        };
    }

    /** Whether the datetime's offset, `Z` or `±HH:MM`, is one of `$offsets`. */
    private static function hasOffsetIn(mixed $value, mixed $offsets): bool
    {
        $datetime = $value instanceof \DateTimeInterface ? DXNDateTime::fromNative($value) : $value;
        if (!$datetime instanceof DXNDateTime) {
            return false;
        }
        $names = $offsets instanceof DXNList ? $offsets->items : $offsets;
        return is_array($names) && in_array(Temporal::offsetName($datetime->offsetMinutes), $names, true);
    }

    private static function countOf(mixed $value): int
    {
        return match (true) {
            $value instanceof DXNList, $value instanceof DXNTuple => count($value->items),
            $value instanceof DXNSet, $value instanceof DXNSortedSet => count($value->items),
            is_array($value) && array_is_list($value) => count($value),
            default => throw new \InvalidArgumentException(
                'min-count/max-count requires a list/tuple/set/sorted-set/array value, got ' . Equality::kindOf($value),
            ),
        };
    }

    /**
     * A collection's items, `null` for a value that is none.
     *
     * @return list<mixed>|null
     */
    private static function itemsOf(mixed $value): ?array
    {
        return match (true) {
            $value instanceof DXNList, $value instanceof DXNTuple => $value->items,
            $value instanceof DXNSet, $value instanceof DXNSortedSet => $value->items,
            is_array($value) && array_is_list($value) => $value,
            default => null,
        };
    }

    /**
     * Whether no two of `$items` give the same identity; `$identify`
     * answers `[identity]`, or `[]` for an item left out.
     *
     * @param list<mixed>|null $items
     * @param \Closure(mixed): list<string> $identify
     */
    private static function distinct(?array $items, \Closure $identify): bool
    {
        if ($items === null) {
            return false;
        }
        $seen = [];
        foreach ($items as $item) {
            foreach ($identify($item) as $id) {
                if (isset($seen[$id])) {
                    return false;
                }
                $seen[$id] = true;
            }
        }
        return true;
    }

    /**
     * Two values are the same when their canonical forms are (DXN.md
     * §1.5): `1` and `1.0` differ, as `name:` and `"name" =>` do. A value
     * with no DXN meaning, an application's object, is compared as itself.
     */
    private static function identity(mixed $value): string
    {
        try {
            return 'dxn:' . Canonical::print(Validated::strip($value));
        } catch (DXNError) {
            return 'term:' . (is_object($value) ? spl_object_hash($value) : serialize($value));
        }
    }

    /** @return list<string> */
    private static function fieldIdentity(mixed $item, string $field): array
    {
        $found = self::fieldOf($item, $field);
        return $found === [] ? [] : [self::identity($found[0])];
    }

    /**
     * The value of the field named `$field`, whatever its key's spelling:
     * `[value]`, or `[]` when there is none.
     *
     * @return list<mixed>
     */
    private static function fieldOf(mixed $value, string $field): array
    {
        if ($value instanceof Validated) {
            return self::fieldOf($value->value, $field);
        }
        if ($value instanceof DXNStruct) {
            if (!$value->isKeyed()) {
                return [];
            }
            foreach ($value->pairs() as [$name, $v]) {
                if ($name === $field) {
                    return [$v];
                }
            }
            return [];
        }
        if ($value instanceof DXNMap) {
            foreach ($value->pairs() as [$key, $v]) {
                if (self::keyName($key) === $field) {
                    return [$v];
                }
            }
            return [];
        }
        if (is_array($value)) {
            foreach ($value as $key => $v) {
                if (self::keyName(MapBuilder::toDxnMapKey($key)) === $field) {
                    return [$v];
                }
            }
            return [];
        }
        if (is_object($value)) {
            $vars = get_object_vars($value);
            return array_key_exists($field, $vars) ? [$vars[$field]] : [];
        }
        return [];
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
