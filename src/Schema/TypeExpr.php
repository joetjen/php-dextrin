<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Binary\Decoder;
use JOetjen\Dextrin\Binary\FloatWidth;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Text\Temporal;
use JOetjen\Dextrin\Value\DXNBits;
use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNCustomTag;
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
use JOetjen\Dextrin\Value\Nanos;

/**
 * Internal, compiled representation of a `.dxns` type expression -- the
 * vocabulary of DXNS.md §1 (`any`, `never`, `primitive`, `reference`,
 * `list-of`, `list-items`, `set-of`, `tuple-of`, `tuple-of-all`, `map-of`, `array-of`, `tagged`, `doc`, `if`,
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
 *
 * @phpstan-type Extras array{entries: list<array{0: TypeExpr, 1: TypeExpr}>, closed: bool}
 */
final class TypeExpr
{
    /**
     * A refine's keys whose argument is a type (DXNS.md §2), compiled,
     * resolved and substituted as its base is.
     *
     * @internal
     */
    public const TYPE_KEYS = ['contains', 'content', 'encodes', 'encodes-seq'];

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
     * @param list<string|array{0: string, 1: self, 2: ?self}>|null $params generic: a bare
     *        parameter's name, or `[name, bound, default]` for one with a bound (and a
     *        default, `null` for none; G2 of dextrin's design/typespec.md)
     * @param list<Particle>|null $particles list-items
     * @param array<string, mixed>|null $notes doc: its annotations by name
     * @param Extras|null $extras shape: what it takes beyond its fields (DXNS.md §4c) -- its
     *        `* K => V` entries in order, and whether `- *` closes it
     * @param int|null $version reference: the one version of the schema it is pinned to
     *        (`Pet(1)`, G11 of dextrin's design/typespec.md, DXNS.md §5c), `null` for any
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
        public readonly ?array $notes = null,
        public readonly ?array $extras = null,
        public readonly ?int $version = null,
    ) {
    }

    /**
     * `%shape{...}` (design/shapes-and-case.md): a plain map with these
     * fields, its missing defaults filled, taking `$extras` beyond them
     * (DXNS.md §4c); open when none are given.
     *
     * @param list<Field> $fields
     * @param Extras|null $extras
     */
    public static function shape(?string $name, array $fields, ?array $extras = null): self
    {
        return new self('shape', name: $name, fields: $fields, extras: $extras ?? self::openExtras());
    }

    /**
     * Extras that say nothing (DXNS.md §4c): any extra is kept, and a
     * strict decode refuses it.
     *
     * @return Extras
     */
    public static function openExtras(): array
    {
        return ['entries' => [], 'closed' => false];
    }

    /**
     * Every type `$extras` hold, each entry's K then V, in order.
     *
     * @internal
     * @param Extras|null $extras
     * @return list<self>
     */
    public static function extrasTypes(?array $extras): array
    {
        return array_merge([], ...array_map(static fn (array $e): array => [$e[0], $e[1]], $extras['entries'] ?? []));
    }

    /**
     * `$extras` with each type its entries hold replaced by what `$map`
     * makes of it.
     *
     * @internal
     * @param Extras|null $extras
     * @param \Closure(self): self $map
     * @return Extras|null
     */
    public static function mapExtras(?array $extras, \Closure $map): ?array
    {
        if ($extras === null) {
            return null;
        }
        return [
            'entries' => array_map(static fn (array $e): array => [$map($e[0]), $map($e[1])], $extras['entries']),
            'closed' => $extras['closed'],
        ];
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

    /**
     * `{:generic [t {u bound} {v bound default}] body}` (DXNS.md §5).
     *
     * @param list<string|array{0: string, 1: self, 2: ?self}> $params
     */
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

    /**
     * This type with every type it holds -- however it holds it: an
     * element, a base, a field's type, a variant, a branch, a particle, a
     * refine's type-valued constraint, a shape's extras, a parameter's
     * bound and default -- replaced by what `$map` makes of it. Not deep:
     * `$map` decides whether to go further.
     *
     * @internal
     * @param \Closure(self): self $map
     */
    public function mapTypes(\Closure $map): self
    {
        $one = static fn (?self $t): ?self => $t === null ? null : $map($t);
        $many = static fn (?array $ts): ?array => $ts === null ? null : array_map($map, $ts);
        return $this->with([
            'elem' => $one($this->elem),
            'base' => $one($this->base),
            'key' => $one($this->key),
            'value' => $one($this->value),
            'otherwise' => $one($this->otherwise),
            'elems' => $many($this->elems),
            'variants' => $many($this->variants),
            'fields' => $this->fields === null ? null : array_map(
                static fn (Field $f): Field => new Field($f->name, $f->required, $map($f->type), $f->default, $f->description),
                $this->fields,
            ),
            'branches' => $this->branches === null ? null : array_map(static fn (array $b): array => [$b[0], $map($b[1])], $this->branches),
            'particles' => $this->particles === null ? null : array_map(static fn (Particle $p): Particle => $p->map($map), $this->particles),
            'constraints' => $this->constraints === null ? null : self::withTypes($this->constraints, $map),
            'extras' => self::mapExtras($this->extras, $map),
            'params' => $this->params === null ? null : array_map(
                static fn (string|array $p): string|array => is_string($p) ? $p : [$p[0], $map($p[1]), $p[2] === null ? null : $map($p[2])],
                $this->params,
            ),
        ]);
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

    /**
     * A struct of the schema `$name`, any loaded version -- or, pinned to
     * `$version` (`Pet(1)`, DXNS.md §5c), only one read or checked at that
     * version, taken as that version reads it.
     */
    public static function reference(string $name, ?int $version = null): self
    {
        return new self('reference', name: $name, version: $version);
    }

    public static function listOf(self $elem): self
    {
        return new self('list-of', elem: $elem);
    }

    /**
     * `{:list-items p ...}`: a list's items in order (DXNS.md §1a).
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

    /**
     * `{:tagged name type}`: a custom tag named `$name` whose value is an
     * `$elem` (DXNS.md §1).
     */
    public static function tagged(string $name, self $elem): self
    {
        return new self('tagged', name: $name, elem: $elem);
    }

    /**
     * `{:doc type %{...}}`: `$elem`, annotated (DXNS.md §1b). The
     * annotations change nothing it takes.
     *
     * @param array<string, mixed> $notes
     */
    public static function doc(self $elem, array $notes): self
    {
        return new self('doc', elem: $elem, notes: $notes);
    }

    /**
     * `{:if test then else}`, JSON Schema's if/then/else: a value that is
     * a `$test` is checked as a `$then`, any other as an `$otherwise`.
     * The test is held as `base`, the then as `elem`.
     */
    public static function if(self $test, self $then, self $otherwise): self
    {
        return new self('if', elem: $then, base: $test, otherwise: $otherwise);
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
        }
        // A reference pinned to one version (G11, DXNS.md §5c).
        if ($this->form === 'reference' && $this->version !== null) {
            return self::conformPinned($this->name ?? '', $this->version, $value);
        }

        // A struct that could not be upgraded is no value of any other
        // type (DXNS.md §5c).
        if ($value instanceof Validated && $value->refused !== null) {
            return NoMatch::Instance;
        }

        switch ($this->form) {
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
                return self::instantiate($generic->params ?? [], $body, $args)->conform($value, $ctx);
            case 'doc':
                // Annotations change nothing a type takes. Passing through
                // something deprecated is reported (G10, DXNS.md §1b), once
                // the value is accepted: a variant tried and refused never
                // warns.
                $conformed = ($this->elem ?? self::any())->conform($value, $ctx);
                if ($conformed === NoMatch::Instance || $this->notes === null || $this->notes === []) {
                    return $conformed;
                }
                return Deprecations::nodeUsed($ctx->registry, $this, $value) ? $conformed : NoMatch::Instance;
            case 'if':
                // A value that is a test is checked as a then, any other as
                // an else -- what the test would fill in is not kept.
                $test = $this->base ?? self::any();
                $chosen = $test->conform($value, $ctx) === NoMatch::Instance ? $this->otherwise : $this->elem;
                return ($chosen ?? self::any())->conform($value, $ctx);
        }

        if ($value instanceof Validated) {
            $inner = $this->conform($value->value, $ctx);
            return $inner === NoMatch::Instance ? NoMatch::Instance : $value->withValue($inner);
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
                // A map whose keys PHP cannot hold as array keys (symbols,
                // UUIDs, dates, ...) is a DXNMap: its pairs are checked as
                // an ordered map's are.
                if ($value instanceof DXNMap) {
                    return $this->conformPairs($value->pairs(), $ctx) === NoMatch::Instance ? NoMatch::Instance : $value;
                }
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
            case 'tagged':
                return $this->conformTagged($value, $ctx);
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
                // nil is a value: a conformed nil is a match, not "no type".
                return ($this->elem === null || $this->elem->conform($value, $ctx) === NoMatch::Instance) ? $value : NoMatch::Instance;
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
                return $value === null ? null : ($this->elem === null ? NoMatch::Instance : $this->elem->conform($value, $ctx));
            case 'refine':
                $c = $this->base === null ? NoMatch::Instance : $this->base->conform($value, $ctx);
                return $c !== NoMatch::Instance && self::satisfiesConstraints($c, $this->constraints ?? [], $this->base, $ctx) ? $c : NoMatch::Instance;
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
     * a time until it does (DXNS.md §1a).
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
            // The key as the DXN value it stands for: a string key is held
            // with a leading space, to tell `"a" =>` from `a:`.
            if ($keyType->conform(MapBuilder::toDxnMapKey($k), $ctx) === NoMatch::Instance) {
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

    // ---- {:tagged} --------------------------------------------------------------

    /**
     * A custom tag of this name, its value conformed to the type; or an
     * application's value that the registry's tag encoder for its class
     * writes as one, whose written value is a match (it is answered
     * itself). An encoder that throws writes nothing that matches.
     */
    private function conformTagged(mixed $value, Context $ctx): mixed
    {
        $type = $this->elem ?? self::any();
        if ($value instanceof DXNCustomTag) {
            if ($value->name !== $this->name) {
                return NoMatch::Instance;
            }
            $inner = $type->conform($value->value, $ctx);
            return $inner === NoMatch::Instance ? NoMatch::Instance : new DXNCustomTag($value->name, $inner);
        }
        $encoder = is_object($value) ? $ctx->registry?->fetchTagEncoder($value::class) : null;
        if ($encoder === null || $encoder[0] !== $this->name) {
            return NoMatch::Instance;
        }
        try {
            $written = $encoder[1]($value);
        } catch (\Throwable) {
            return NoMatch::Instance;
        }
        return $type->conform($written, $ctx) === NoMatch::Instance ? NoMatch::Instance : $value;
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
     * keyword key. What it does with an extra key, one no field declares,
     * its extras say (G1, DXNS.md §4c): open ones are kept, and refused by
     * a strict decode -- as a struct's fields are
     * (design/shapes-and-case.md); closed ones (`- *`) are refused; typed
     * ones (`* K => V`) are each conformed, key and value.
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
                $added[] = [new DXNKeyword($field->name), $field->defaultValue()];
            } elseif ($field->required) {
                return NoMatch::Instance;
            }
        }
        $declared = array_map(static fn (Field $f): string => $f->name, $this->fields ?? []);
        $extras = $this->extras ?? self::openExtras();
        $out = [];
        foreach ($pairs as $i => $pair) {
            if (array_key_exists($i, $replaced)) {
                $out[] = [$pair[0], $replaced[$i]];
                continue;
            }
            $name = self::keyName($pair[0]);
            if ($name !== null && in_array($name, $declared, true)) {
                $out[] = $pair;
                continue;
            }
            [$outcome, $value] = self::extraConformed($extras, $pair[0], $pair[1], $ctx);
            if ($outcome !== 'ok') {
                return NoMatch::Instance;
            }
            $out[] = [$pair[0], $value];
        }
        $pairs = [...$out, ...$added];
        return $map instanceof DXNMap ? new DXNMap($pairs) : MapBuilder::build($pairs, static fn (string $m): \Throwable => new \LogicException($m));
    }

    /**
     * A reference pinned to one version (G11, DXNS.md §5c): a struct read
     * or checked with that version, and nothing else. What it takes is the
     * struct as that version reads it -- no materializer upgrades it, and
     * a refusal for want of one no longer stands.
     */
    private static function conformPinned(string $name, int $version, mixed $value): mixed
    {
        if ($value instanceof Validated && $value->name === $name && $value->version === $version) {
            return $value->asRead === null ? $value : new Validated($name, $value->asRead, $value->also, $version, $value->asRead);
        }
        if ($value instanceof DXNStruct && $value->name === $name && $value->version === $version) {
            return $value;
        }
        return NoMatch::Instance;
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

    // ---- why a value is refused ----------------------------------------------------

    /**
     * Why `$type` refuses `$value`, in words, or `null` when there is
     * nothing more to say than that it does not match. Asked only after
     * {@see self::conform()} refused, so a check that passes never pays
     * for it.
     *
     * It follows a refusal to where a document said something about it:
     * a `messages` annotation's message for the constraint that refused
     * (G5 of dextrin's design/typespec.md), and a `discriminator`
     * annotation's choice of variant (G3) -- the variant the field names,
     * and why that one refuses, instead of every variant's refusal --
     * through a use of a generic, and into a shape's fields, to the first
     * that is missing or refused.
     *
     * @internal
     * @param self $type the type that refused
     * @param mixed $value the value it refused
     * @param Context $ctx the check's context
     * @return string|null why, or `null` when nothing more is known
     */
    public static function explain(self $type, mixed $value, Context $ctx): ?string
    {
        switch ($type->form) {
            case 'doc':
                $notes = $type->notes ?? [];
                $inner = $type->elem ?? self::any();
                if (array_key_exists('discriminator', $notes)) {
                    $variants = self::variantsOf($inner);
                    if ($variants === null) {
                        unset($notes['discriminator']);
                        return self::explain(self::doc($inner, $notes), $value, $ctx);
                    }
                    return self::discriminated((string) $notes['discriminator'], $variants, $value, $ctx);
                }
                if (array_key_exists('messages', $notes) && is_array($notes['messages'])) {
                    $message = self::message($notes['messages'], self::undocType($inner), $value, $ctx);
                    return $message ?? self::explain($inner, $value, $ctx);
                }
                return self::explain($inner, $value, $ctx);
            case 'named':
                $named = $ctx->registry?->fetchTypeAlias($type->name ?? '');
                return $named === null ? null : self::explain($named, $value, $ctx);
            case 'apply':
                // A use of a generic: why its body, the arguments in place,
                // refuses.
                $generic = $ctx->registry?->fetchTypeAlias($type->name ?? '');
                return $generic === null || $generic->form !== 'generic'
                    ? null
                    : self::explain(self::instantiate($generic->params ?? [], $generic->base ?? self::any(), $type->elems ?? []), $value, $ctx);
            case 'shape':
                return is_array($value) || $value instanceof DXNMap ? self::shapeRefusal($type, $value, $ctx) : null;
            case 'reference':
                if ($type->version === null) {
                    return null;
                }
                $got = match (true) {
                    $value instanceof Validated && $value->version !== null => DXNTuple::pinnedText($value->name, $value->version),
                    $value instanceof Validated => $value->name,
                    $value instanceof DXNStruct && $value->version !== null => DXNTuple::pinnedText($value->name, $value->version),
                    $value instanceof DXNStruct => $value->name,
                    default => 'no struct',
                };
                return 'expected ' . DXNTuple::pinnedText($type->name ?? '', $type->version) . ", got {$got}";
            default:
                return null;
        }
    }

    /**
     * The message `$messages` gives for the constraint of the refine
     * `$type` that refuses `$value`, or `null`: when `$type` is no refine,
     * its base refuses the value, or no message names that constraint.
     *
     * @param array<mixed> $messages
     */
    private static function message(array $messages, self $type, mixed $value, Context $ctx): ?string
    {
        if ($type->form !== 'refine') {
            return null;
        }
        $base = $type->base ?? self::any();
        $conformed = $base->conform($value, $ctx);
        if ($conformed === NoMatch::Instance) {
            return null;
        }
        $key = self::failingKey($conformed, $type->constraints ?? [], $base, $ctx);
        $message = $key === null ? null : ($messages[$key] ?? null);
        return is_string($message) ? $message : null;
    }

    /**
     * The first constraint, in the order of their names, that `$value`
     * fails, each checked with the keys it reads beside its own.
     *
     * @param array<string, mixed> $constraints
     */
    private static function failingKey(mixed $value, array $constraints, self $base, Context $ctx): ?string
    {
        $keys = array_map('strval', array_keys($constraints));
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $alone = array_intersect_key($constraints, array_flip([$key, ...self::companions($key)]));
            if (!self::satisfiesConstraints($value, $alone, $base, $ctx)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * The keys a constraint reads beside its own.
     *
     * @return list<string>
     */
    private static function companions(string $key): array
    {
        return match ($key) {
            'contains' => ['min-contains', 'max-contains'],
            'content' => ['encoding', 'media-type'],
            default => [],
        };
    }

    /**
     * The first of a shape's fields that is missing or refused, or else
     * the first extra its extras refuse (DXNS.md §4c), and why.
     *
     * @param array<int|string, mixed>|DXNMap $map
     */
    private static function shapeRefusal(self $shape, array|DXNMap $map, Context $ctx): ?string
    {
        $pairs = $map instanceof DXNMap
            ? $map->pairs()
            : array_map(static fn (int|string $k, mixed $v): array => [MapBuilder::toDxnMapKey($k), $v], array_keys($map), array_values($map));
        $siblings = [];
        foreach ($pairs as [$key, $v]) {
            $name = self::keyName($key);
            if ($name !== null) {
                $siblings[$name] = $v;
            }
        }
        $inner = new Context($ctx->registry, $siblings);
        $why = self::fieldRefusal($shape, $map, $inner);
        if ($why !== null) {
            return $why;
        }
        $refusal = self::extrasRefusal($shape->extras ?? self::openExtras(), self::undeclared($shape->fields ?? [], $pairs), $ctx);
        return $refusal === null ? null : $refusal[0];
    }

    /**
     * The first of a shape's fields that is missing or refused, and why.
     *
     * @param array<int|string, mixed>|DXNMap $map
     */
    private static function fieldRefusal(self $shape, array|DXNMap $map, Context $inner): ?string
    {
        foreach ($shape->fields ?? [] as $field) {
            $found = self::fieldOf($map, $field->name);
            if ($found !== []) {
                if ($field->type->conform($found[0], $inner) === NoMatch::Instance) {
                    $why = self::explain($field->type, $found[0], $inner);
                    return $why === null ? "field \"{$field->name}\" does not match its type" : "field \"{$field->name}\": {$why}";
                }
            } elseif ($field->required && $field->default === Field::NO_DEFAULT) {
                return "field \"{$field->name}\" is missing";
            }
        }
        return null;
    }

    /**
     * The `[key, value]`s of `$pairs` no field of `$fields` declares.
     *
     * @param list<Field> $fields
     * @param list<array{0: mixed, 1: mixed}> $pairs
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function undeclared(array $fields, array $pairs): array
    {
        $declared = array_map(static fn (Field $f): string => $f->name, $fields);
        return array_values(array_filter($pairs, static function (array $pair) use ($declared): bool {
            $name = self::keyName($pair[0]);
            return $name === null || !in_array($name, $declared, true);
        }));
    }

    /**
     * One extra, `$key => $value`, against `$extras` (DXNS.md §4c): its
     * value must match the V of every entry whose K takes the key, in
     * order. A key no entry takes is kept, unless the body is closed or
     * the decode strict. Answers `['ok', conformed value]`,
     * `['closed' | 'strict', null]` for a key nothing takes, or
     * `['mismatch', V]` for the V it does not match.
     *
     * @param Extras $extras
     * @return array{0: 'ok'|'closed'|'strict'|'mismatch', 1: mixed}
     */
    private static function extraConformed(array $extras, mixed $key, mixed $value, Context $ctx): array
    {
        $matched = array_values(array_filter(
            $extras['entries'],
            static fn (array $entry): bool => $entry[0]->conform($key, $ctx) !== NoMatch::Instance,
        ));
        if ($matched === []) {
            return match (true) {
                $extras['closed'] => ['closed', null],
                $ctx->registry !== null && $ctx->registry->isStrict() => ['strict', null],
                default => ['ok', $value],
            };
        }
        foreach ($matched as [, $valueType]) {
            $conformed = $valueType->conform($value, $ctx);
            if ($conformed === NoMatch::Instance) {
                return ['mismatch', $valueType];
            }
            $value = $conformed;
        }
        return ['ok', $value];
    }

    /**
     * Why `$extras` refuse `$pairs`, the `[key, value]`s a struct or map
     * carries that no field declares (DXNS.md §4c): `null` when they pass,
     * otherwise `[message, category]`. A key some `* K => V` takes must
     * match its V; one none takes is refused when the body is closed or
     * the decode strict.
     *
     * @internal
     * @param Extras $extras what the schema or shape takes
     * @param list<array{0: mixed, 1: mixed}> $pairs the undeclared entries
     * @param Context $ctx the check's context
     * @return array{0: string, 1: string}|null
     */
    public static function extrasRefusal(array $extras, array $pairs, Context $ctx): ?array
    {
        foreach ($pairs as [$key, $value]) {
            [$outcome, $detail] = self::extraConformed($extras, $key, $value, $ctx);
            switch ($outcome) {
                case 'closed':
                    return [self::shown($key) . ' is no declared field, and the body is closed (- *)', 'schema/undeclared-field'];
                case 'strict':
                    return [self::shown($key) . ' is no declared field, and the decode is strict', 'schema/undeclared-field'];
                case 'mismatch':
                    /** @var self $detail */
                    $why = self::explain($detail, $value, $ctx) ?? 'does not match the value type of * K => V';
                    return ['extra ' . self::shown($key) . ": {$why}", 'schema/type-mismatch'];
            }
        }
        return null;
    }

    private static function undocType(self $type): self
    {
        return $type->form === 'doc' ? self::undocType($type->elem ?? self::any()) : $type;
    }

    /** @return list<self>|null an any-of's or one-of's variants, through docs */
    private static function variantsOf(self $type): ?array
    {
        return match ($type->form) {
            'doc' => self::variantsOf($type->elem ?? self::any()),
            'any-of', 'one-of' => $type->variants ?? [],
            default => null,
        };
    }

    /**
     * The variant whose discriminating field takes the value's, and why
     * that variant refuses it.
     *
     * @param list<self> $variants
     */
    private static function discriminated(string $field, array $variants, mixed $value, Context $ctx): string
    {
        $found = self::fieldOf($value, $field);
        if ($found === []) {
            return "field \"{$field}\" is missing; it chooses the variant";
        }
        $chosen = $found[0];
        foreach ($variants as $variant) {
            $type = self::variantField($variant, $field, $ctx);
            if ($type !== null && $type->conform($chosen, $ctx) !== NoMatch::Instance) {
                $why = self::explain($variant, $value, $ctx) ?? 'it does not match';
                return "{$field} " . self::shown($chosen) . ' chooses ' . self::variantName($variant) . ": {$why}";
            }
        }
        return "{$field} " . self::shown($chosen) . ' names no variant';
    }

    /**
     * The type a variant gives its field `$name`, looked up through names:
     * a shape's, or a schema's (its latest version, or the one it is pinned
     * to).
     */
    private static function variantField(self $type, string $name, Context $ctx): ?self
    {
        switch ($type->form) {
            case 'doc':
                return self::variantField($type->elem ?? self::any(), $name, $ctx);
            case 'named':
                $named = $ctx->registry?->fetchTypeAlias($type->name ?? '');
                return $named === null ? null : self::variantField($named, $name, $ctx);
            case 'shape':
                return self::fieldType($type->fields ?? [], $name);
            case 'reference':
                // A schema variant: its field in the latest compiled
                // version, or in the version it is pinned to (G11).
                try {
                    $found = $ctx->registry?->fetchStructSchema($type->name ?? '', $type->version);
                } catch (\Throwable) {
                    // A resolver's refusal surfaces where the struct is read.
                    return null;
                }
                return $found === null ? null : self::fieldType($found->compiled->fields, $name);
            default:
                return null;
        }
    }

    /**
     * The type of the field named `$name` among `$fields`, or `null`.
     *
     * @param list<Field> $fields
     */
    private static function fieldType(array $fields, string $name): ?self
    {
        foreach ($fields as $field) {
            if ($field->name === $name) {
                return $field->type;
            }
        }
        return null;
    }

    private static function variantName(self $type): string
    {
        return match (true) {
            $type->form === 'doc' => self::variantName($type->elem ?? self::any()),
            // A pinned variant is named by its pin (G11): `Pet(1)`.
            $type->form === 'reference' && $type->version !== null => DXNTuple::pinnedText((string) $type->name, $type->version),
            $type->form === 'named', $type->form === 'reference' => (string) $type->name,
            $type->form === 'shape' && $type->name !== null => $type->name,
            default => 'a variant',
        };
    }

    /** `$value` as DXN text, for a message; its PHP type when it has none. */
    private static function shown(mixed $value): string
    {
        try {
            return Dextrin::encode($value);
        } catch (\Throwable) {
            return get_debug_type($value);
        }
    }

    // ---- generics -----------------------------------------------------------------

    /**
     * A generic's body with `$args` standing where its parameters stand:
     * the type one `{:of Name ...}` of it means. A parameter past the
     * arguments stays standing.
     *
     * @internal
     * @param list<string|array{0: string, 1: self, 2: ?self}> $params
     * @param list<self> $args
     */
    public static function instantiate(array $params, self $body, array $args): self
    {
        $bound = [];
        foreach (array_slice($params, 0, count($args)) as $i => $param) {
            $bound[self::paramName($param)] = $args[$i];
        }
        return $body->substitute($bound);
    }

    /**
     * A generic parameter's name: a bare parameter is its name, one with
     * a bound or a default `[name, bound, default]` (G2 of dextrin's
     * design/typespec.md).
     *
     * @internal
     * @param string|array{0: string, 1: self, 2: ?self} $param
     */
    public static function paramName(string|array $param): string
    {
        return is_string($param) ? $param : $param[0];
    }

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
            'shape' => $this->with([
                'fields' => array_map(
                    static fn (Field $f): Field => new Field($f->name, $f->required, $f->type->substitute($bound), $f->default, $f->description),
                    $this->fields ?? [],
                ),
                'extras' => self::mapExtras($this->extras, static fn (self $t): self => $t->substitute($bound)),
            ]),
            'case' => $this->with([
                'branches' => array_map(static fn (array $b): array => [$b[0], $b[1]->substitute($bound)], $this->branches ?? []),
                'otherwise' => $sub($this->otherwise),
            ]),
            'refine' => $this->with(['base' => $sub($this->base), 'constraints' => self::withTypes(
                $this->constraints ?? [],
                static fn (self $t): self => $t->substitute($bound),
            )]),
            'list-items' => $this->with(['particles' => array_map(
                static fn (Particle $p): Particle => $p->map(static fn (self $t): self => $t->substitute($bound)),
                $this->particles ?? [],
            )]),
            'one-of', 'any-of', 'all-of' => $this->with(['variants' => $subAll($this->variants)]),
            'list-of', 'set-of', 'array-of', 'sorted-set-of', 'tuple-of-all', 'not', 'nilable', 'tagged', 'doc' => $this->with(['elem' => $sub($this->elem)]),
            'if' => $this->with(['base' => $sub($this->base), 'elem' => $sub($this->elem), 'otherwise' => $sub($this->otherwise)]),
            'map-of', 'ordered-map-of' => $this->with(['key' => $sub($this->key), 'value' => $sub($this->value)]),
            default => $this,
        };
    }

    /**
     * A refine's `$constraints` with each type they hold -- `contains`,
     * `content`, `encodes`, `encodes-seq` -- replaced by what `$map`
     * makes of it.
     *
     * @param array<string, mixed> $constraints
     * @param \Closure(self): self $map
     * @return array<string, mixed>
     */
    public static function withTypes(array $constraints, \Closure $map): array
    {
        foreach (self::TYPE_KEYS as $key) {
            if (($constraints[$key] ?? null) instanceof self) {
                $constraints[$key] = $map($constraints[$key]);
            }
        }
        return $constraints;
    }

    /**
     * The types a refine's `$constraints` hold, in {@see self::TYPE_KEYS}' order.
     *
     * @internal
     * @param array<string, mixed> $constraints
     * @return list<self>
     */
    public static function heldTypes(array $constraints): array
    {
        $held = [];
        foreach (self::TYPE_KEYS as $key) {
            if (($constraints[$key] ?? null) instanceof self) {
                $held[] = $constraints[$key];
            }
        }
        return $held;
    }

    private static function matchesReference(string $name, mixed $value, ?Registry $registry): bool
    {
        if ($value instanceof DXNStruct) {
            return $value->name === $name;
        }
        // A value of a schema that extends `$name`, and is a valid `$name`
        // as well, is accepted where a `$name` is (DXNS.md §4b).
        if ($value instanceof Validated) {
            return $value->name === $name || in_array($name, $value->also, true);
        }
        // A struct is matched only by a struct (DXNS.md §7): past a
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

    /**
     * Whether `$value` is a value of the primitive type `$name` (`string`,
     * `uuid`, `decimal`, ...): the check a `{:primitive}` makes, for code
     * generated from a schema ({@see \JOetjen\Dextrin\Codegen\Php}), which
     * holds no compiled type.
     *
     * @param string $name the primitive's name, as DXNS writes it without the colon
     * @param mixed $value the value
     * @return bool whether it is one; `false` for a name that is no primitive
     */
    public static function isPrimitive(string $name, mixed $value): bool
    {
        return self::primitiveMatches($name, $value);
    }

    /**
     * Whether `$value` is one of `$literals`, as an `{:enum}` compares
     * them: for generated code.
     *
     * @param list<mixed> $literals the enum's literals
     * @param mixed $value the value
     * @return bool whether it is equal to one of them
     */
    public static function isEnumMember(array $literals, mixed $value): bool
    {
        return self::enum($literals)->enumMatches($value);
    }

    /**
     * Whether `$value` meets every one of a `{:refine}`'s `$constraints`,
     * for generated code. `$primitive` is the primitive the refine stands
     * on, when it stands on one: `bits` makes a length count bits. The
     * keys whose argument is a type ({@see self::TYPE_KEYS}) need a
     * registry and are not met here.
     *
     * @param mixed $value the value, already of the refined type
     * @param array<string, mixed> $constraints the constraints by key
     * @param string|null $primitive the primitive refined, if any
     * @return bool whether all of them hold
     */
    public static function meetsConstraints(mixed $value, array $constraints, ?string $primitive = null): bool
    {
        return self::satisfiesConstraints($value, $constraints, $primitive === null ? null : self::primitive($primitive), new Context());
    }

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
            // indistinguishable and reads as `array`, not `map`. A map
            // PHP cannot hold as an array -- `%{}` decoded, or one keyed by
            // a uuid, a date, ... -- is a DXNMap, and a map all the same.
            'map' => (is_array($value) && !array_is_list($value)) || $value instanceof DXNMap,
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
            // Bits and bytes are one value space (DXNS.md §1): `:bits` takes both.
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

    /** A resolution's unit, in nanoseconds. */
    private const RESOLUTIONS = [
        'second' => 1_000_000_000,
        'millisecond' => 1_000_000,
        'microsecond' => 1_000,
        'nanosecond' => 1,
    ];

    /**
     * The units `resolution` takes, for {@see Compiler} to check.
     *
     * @internal
     * @return list<string>
     */
    public static function resolutions(): array
    {
        return array_keys(self::RESOLUTIONS);
    }

    /**
     * Every constraint holds. A constraint on a value it does not apply
     * to -- a length on an integer, a range on a string -- refuses it
     * (DXNS.md §2, Jan, 2026-10-20). `$base` is the type refined, which
     * says whether a length counts bytes or bits.
     *
     * @param array<string, mixed> $constraints
     */
    private static function satisfiesConstraints(mixed $value, array $constraints, ?self $base, Context $ctx): bool
    {
        foreach ($constraints as $key => $arg) {
            $satisfied = match ($key) {
                'contains' => $arg instanceof self && self::contains($arg, $constraints, $value, $ctx),
                'content' => $arg instanceof self && self::hasContent($arg, $constraints, $value, $ctx),
                'encodes' => $arg instanceof self && self::encodes($arg, $value, $ctx),
                'encodes-seq' => $arg instanceof self && self::encodesSeq($arg, $value, $ctx),
                'min-length' => self::within(self::lengthOf($value, $base), $arg, null),
                'max-length' => self::within(self::lengthOf($value, $base), null, $arg),
                default => self::satisfiesConstraint($key, $arg, $value),
            };
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
        return self::within($count, $constraints['min-contains'] ?? 1, $constraints['max-contains'] ?? null);
    }

    /**
     * The string, decoded by its `encoding` when it has one, is a DXN
     * document whose value is a `$type`: `.dxnb` under application/dxnb,
     * text otherwise.
     *
     * @param array<string, mixed> $constraints
     */
    private static function hasContent(self $type, array $constraints, mixed $value, Context $ctx): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $encoding = $constraints['encoding'] ?? null;
        $bytes = is_string($encoding) ? self::decoded($encoding, $value) : $value;
        if ($bytes === null) {
            return false;
        }
        $media = $constraints['media-type'] ?? null;
        $binary = is_string($media) && strtolower($media) === 'application/dxnb';
        if (!$binary && !mb_check_encoding($bytes, 'UTF-8')) {
            return false;
        }
        $documents = self::read(static fn (): array => [$binary ? Dextrin::decodeBinary($bytes) : Dextrin::decode($bytes)]);
        return $documents !== null && self::allConform($type, $documents, $ctx);
    }

    /** The bytes are a `.dxnb` document whose value is a `$type`. */
    private static function encodes(self $type, mixed $value, Context $ctx): bool
    {
        if (!$value instanceof DXNBytes) {
            return false;
        }
        $documents = self::read(static fn (): array => [Dextrin::decodeBinary($value->data)]);
        return $documents !== null && self::allConform($type, $documents, $ctx);
    }

    /** The bytes are `.dxnb` documents back to back, each a `$type`; none at all too. */
    private static function encodesSeq(self $type, mixed $value, Context $ctx): bool
    {
        if (!$value instanceof DXNBytes) {
            return false;
        }
        $documents = self::read(static fn (): array => Decoder::decodeSequence($value->data));
        return $documents !== null && self::allConform($type, $documents, $ctx);
    }

    /**
     * What `$decode` reads, or `null` when it fails: a document that does
     * not read is content of no type, never an error of the check.
     *
     * @param \Closure(): list<mixed> $decode
     * @return list<mixed>|null
     */
    private static function read(\Closure $decode): ?array
    {
        try {
            return $decode();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<mixed> $documents */
    private static function allConform(self $type, array $documents, Context $ctx): bool
    {
        foreach ($documents as $document) {
            if ($type->conform($document, $ctx) === NoMatch::Instance) {
                return false;
            }
        }
        return true;
    }

    /**
     * `$text` decoded by RFC 4648's `$encoding`, `null` when it is not
     * valid in it: base64 and base32 with their padding, base64url with
     * or without it, base32 in upper case, base16 in either. The
     * alphabet and the padding are checked here, as PHP's decoders take
     * more than the reference's; the bits past the last byte are not,
     * as the reference does not check them.
     */
    private static function decoded(string $encoding, string $text): ?string
    {
        return match ($encoding) {
            'base64' => preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D', $text) === 1
                ? self::orNull(base64_decode($text, true))
                : null,
            'base64url' => preg_match(
                str_contains($text, '=')
                    ? '/^(?:[A-Za-z0-9_-]{4})*(?:[A-Za-z0-9_-]{2}==|[A-Za-z0-9_-]{3}=)?$/D'
                    : '/^(?:[A-Za-z0-9_-]{4})*(?:[A-Za-z0-9_-]{2,3})?$/D',
                $text,
            ) === 1
                ? self::orNull(base64_decode(strtr($text, '-_', '+/'), true))
                : null,
            'base32' => preg_match('/^(?:[A-Z2-7]{8})*(?:[A-Z2-7]{2}={6}|[A-Z2-7]{4}={4}|[A-Z2-7]{5}={3}|[A-Z2-7]{7}=)?$/D', $text) === 1
                ? self::base32(rtrim($text, '='))
                : null,
            'base16' => preg_match('/^(?:[0-9A-Fa-f]{2})*$/D', $text) === 1 ? self::orNull(hex2bin($text)) : null,
            default => null,
        };
    }

    private static function orNull(string|false $bytes): ?string
    {
        return $bytes === false ? null : $bytes;
    }

    /** Unpadded upper-case base32, its alphabet checked by the caller, as bytes; leftover bits dropped. */
    private static function base32(string $text): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($text) as $char) {
            $buffer = (($buffer << 5) | (int) strpos($alphabet, $char)) & 0xFFF;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $bytes .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $bytes;
    }

    /**
     * Whether `$count` lies between `$least` and `$most`, either `null`
     * for no bound; a `null` count, of a value that has none, never does,
     * nor does a bound that is no number.
     */
    private static function within(?int $count, mixed $least, mixed $most): bool
    {
        if ($count === null) {
            return false;
        }
        if ($least !== null && !(self::compareCount($count, $least) >= 0)) {
            return false;
        }
        return $most === null || self::compareCount($count, $most) <= 0;
    }

    /**
     * `$count` against a bound, -1, 0 or 1; `null` for a bound that is
     * no number, which the caller's comparisons with -1, 0 and 1 refuse.
     */
    private static function compareCount(int $count, mixed $bound): ?int
    {
        return match (true) {
            is_int($bound) => $count <=> $bound,
            is_float($bound) && !is_nan($bound) => $count <=> $bound,
            $bound instanceof DXNInteger => bccomp((string) $count, $bound->toString(), 0),
            default => null,
        };
    }

    /**
     * One refine key against `$value` (DXNS.md §2). NaN lies outside
     * every range, as every IEEE 754 comparison with it is false; the
     * infinities compare as the numbers they are (Jan, 2026-10-20).
     * Dates, times and durations compare with their own kind.
     */
    private static function satisfiesConstraint(string $key, mixed $arg, mixed $value): bool
    {
        return match ($key) {
            'min' => in_array(self::compare($value, $arg), [1, 0], true),
            'max' => in_array(self::compare($value, $arg), [-1, 0], true),
            'exclusive-min' => self::compare($value, $arg) === 1,
            'exclusive-max' => self::compare($value, $arg) === -1,
            // `finite: true` refuses NaN and both infinities; either asks for a number.
            'finite' => match ($arg) {
                true => self::isNumber($value) && (!is_float($value) || is_finite($value)),
                false => self::isNumber($value),
                default => false,
            },
            // A float single precision holds exactly; NaN and the infinities it holds.
            'float32' => $arg === true && is_float($value) && is_finite($value)
                ? FloatWidth::single($value)
                : is_float($value),
            // An ip's version, 4 or 6, and whether it is a prefix.
            'version' => $value instanceof DXNIp && $value->version() === $arg,
            'prefix' => $value instanceof DXNIp && ($value->prefix !== null) === $arg,
            'offsets' => self::hasOffsetIn($value, $arg),
            'multiple-of' => self::isMultipleOf($value, $arg),
            'min-bytes' => self::within(self::utf8Size($value), $arg, null),
            'max-bytes' => self::within(self::utf8Size($value), null, $arg),
            'pattern' => self::matchesPattern($value, $arg),
            // Valid in its RFC 4648 encoding.
            'encoding' => is_string($value) && is_string($arg) && self::decoded($arg, $value) !== null,
            // What the string holds; it checks nothing more (`content` reads it).
            'media-type' => is_string($value),
            // One of DXNS.md §2a's formats, checked as Format says.
            'format' => is_string($value) && is_string($arg) && Format::valid($arg, $value),
            'min-count' => self::within(self::countOf($value), $arg, null),
            'max-count' => self::within(self::countOf($value), null, $arg),
            // Counted with `contains`, which reads them.
            'min-contains', 'max-contains' => true,
            'unique' => match ($arg) {
                false => self::itemsOf($value) !== null,
                true => self::distinct(self::itemsOf($value), static fn (mixed $item): array => [self::identity($item)]),
                default => false,
            },
            // Items without the field are compared with none.
            'unique-by' => is_string($arg) && self::distinct(self::itemsOf($value), static fn (mixed $item): array => self::fieldIdentity($item, $arg)),
            // A decimal's precision is its coefficient's digit count, as
            // written (`1.50M` has three); its exponent the power of ten
            // that scales them.
            'min-precision' => self::within(self::precision($value), $arg, null),
            'max-precision' => self::within(self::precision($value), null, $arg),
            'min-exponent' => self::within(self::exponent($value), $arg, null),
            'max-exponent' => self::within(self::exponent($value), null, $arg),
            'bits' => self::onlyBits($value, $arg),
            // Against the time of the check.
            'before-now' => self::fromNow($value, $arg, static fn (string $d): bool => bccomp(self::numeric($d), '0', 0) < 0),
            'after-now' => self::fromNow($value, $arg, static fn (string $d): bool => bccomp(self::numeric($d), '0', 0) > 0),
            'within' => self::withinOfNow($value, $arg),
            // No part of the value is finer than the unit.
            'resolution' => self::hasResolution($value, $arg),
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

    /** Whether `$value` is a number, NaN and the infinities included. */
    private static function isNumber(mixed $value): bool
    {
        return (self::ordinal($value)[0] ?? null) === 'number';
    }

    /**
     * Whether the number `$value` is an exact multiple of `$n`: two
     * integers by their remainder, anything else by the floats' (which a
     * zero, NaN or an infinity never is).
     */
    private static function isMultipleOf(mixed $value, mixed $n): bool
    {
        $v = self::ordinal($value);
        $by = self::ordinal($n);
        if ($v === null || $by === null || $v[0] !== 'number' || $by[0] !== 'number') {
            return false;
        }
        [, $a] = $v;
        [, $b] = $by;
        if (is_float($a) && !is_finite($a)) {
            return false;
        }
        if (!is_float($a) && !is_float($b)) {
            return bccomp(self::numeric($b), '0', 0) !== 0 && bccomp(bcmod(self::numeric($a), self::numeric($b), 0), '0', 0) === 0;
        }
        return fmod((float) $a, (float) $b) === 0.0;
    }

    /**
     * A string's, keyword's or symbol's length in codepoints; a byte
     * string's in bytes, in bits under `:bits`; `null` for anything else.
     */
    private static function lengthOf(mixed $value, ?self $base): ?int
    {
        $bits = $base !== null && $base->form === 'primitive' && $base->name === 'bits';
        return match (true) {
            is_string($value) => mb_strlen($value, 'UTF-8'),
            $value instanceof DXNKeyword, $value instanceof DXNSymbol => mb_strlen($value->name, 'UTF-8'),
            $value instanceof DXNBytes => $bits ? strlen($value->data) * 8 : strlen($value->data),
            $value instanceof DXNBits => $bits ? $value->bitLength : intdiv($value->bitLength + 7, 8),
            default => null,
        };
    }

    /** A string's, keyword's or symbol's UTF-8 byte count; `null` for anything else. */
    private static function utf8Size(mixed $value): ?int
    {
        return match (true) {
            is_string($value) => strlen($value),
            $value instanceof DXNKeyword, $value instanceof DXNSymbol => strlen($value->name),
            default => null,
        };
    }

    /**
     * Whether `$value` matches the regex `$re`: a name's text, or a byte
     * string one character per byte -- its bytes as they are, or, for a
     * pattern with the `u` flag, as the Latin-1 characters they are.
     */
    private static function matchesPattern(mixed $value, mixed $re): bool
    {
        if (!$re instanceof DXNRegex) {
            return false;
        }
        $text = match (true) {
            is_string($value) => $value,
            $value instanceof DXNKeyword, $value instanceof DXNSymbol => $value->name,
            $value instanceof DXNBytes => $re->flags['u'] ? mb_convert_encoding($value->data, 'UTF-8', 'ISO-8859-1') : $value->data,
            default => null,
        };
        if ($text === null) {
            return false;
        }
        try {
            return preg_match($re->toPcrePattern(), $text) === 1;
        } catch (\LogicException) {
            // A flag PHP's PCRE has no modifier for.
            return false;
        }
    }

    /** A decimal's coefficient's digit count, as written; `null` for anything else. */
    private static function precision(mixed $value): ?int
    {
        return $value instanceof DXNDecimal ? strlen($value->unscaledValue) : null;
    }

    /** A decimal's exponent; `null` for anything else. */
    private static function exponent(mixed $value): ?int
    {
        return $value instanceof DXNDecimal ? $value->exponent : null;
    }

    /**
     * Whether only the bits numbered in `$allowed` are set (RFC 8610's
     * `.bits`): a non-negative integer's from its lowest, a byte
     * string's from its first byte's lowest. Both are read as bytes,
     * the integer's lowest first.
     */
    private static function onlyBits(mixed $value, mixed $allowed): bool
    {
        $numbers = $allowed instanceof DXNList ? $allowed->items : $allowed;
        if (!is_array($numbers)) {
            return false;
        }
        foreach ($numbers as $number) {
            // A big bit number names a bit past any value's: it allows nothing more.
            if (!is_int($number) && !$number instanceof DXNInteger) {
                return false;
            }
        }
        $bytes = self::bitBytes($value);
        if ($bytes === null) {
            return false;
        }
        foreach (str_split($bytes) as $i => $byte) {
            if ((ord($byte) & ~self::mask($numbers, $i * 8)) !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * The bytes `bits` reads: a byte string's own, a non-negative
     * integer's lowest first; `null` for anything else.
     */
    private static function bitBytes(mixed $value): ?string
    {
        if ($value instanceof DXNBytes) {
            return $value->data;
        }
        if (is_int($value) && $value >= 0) {
            $bytes = '';
            for ($n = $value; $n > 0; $n >>= 8) {
                $bytes .= chr($n & 0xFF);
            }
            return $bytes;
        }
        if ($value instanceof DXNInteger && !str_starts_with($value->toString(), '-')) {
            $bytes = '';
            for ($n = $value->toString(); bccomp($n, '0', 0) > 0; $n = bcdiv($n, '256', 0)) {
                $bytes .= chr((int) bcmod($n, '256', 0));
            }
            return $bytes;
        }
        return null;
    }

    /**
     * The bits of `$allowed` that fall in the byte starting at bit
     * `$from`, shifted down to it.
     *
     * @param array<mixed> $allowed integers, checked by the caller
     */
    private static function mask(array $allowed, int $from): int
    {
        $mask = 0;
        foreach ($allowed as $bit) {
            if (is_int($bit) && $bit >= $from && $bit < $from + 8) {
                $mask |= 1 << ($bit - $from);
            }
        }
        return $mask;
    }

    /**
     * A datetime's distance from now, in nanoseconds, given to `$test`;
     * `$wanted` false asks nothing, and anything but a boolean refuses.
     *
     * @param \Closure(string): bool $test
     */
    private static function fromNow(mixed $value, mixed $wanted, \Closure $test): bool
    {
        if ($wanted === false) {
            return true;
        }
        $at = self::ordinal($value);
        if ($wanted !== true || $at === null || $at[0] !== 'datetime') {
            return false;
        }
        return $test(bcsub(self::numeric($at[1]), self::nowNanos(), 0));
    }

    /** Whether a datetime is no further from now than the duration `$span`, either way. */
    private static function withinOfNow(mixed $value, mixed $span): bool
    {
        $duration = self::ordinal($span);
        if ($duration === null || $duration[0] !== 'duration') {
            return false;
        }
        $most = self::numeric($duration[1]);
        return self::fromNow($value, true, static fn (string $d): bool => bccomp(self::numeric(ltrim($d, '-')), $most, 0) <= 0);
    }

    /**
     * The time of the check, as nanoseconds since 1970, a decimal string.
     *
     * @return numeric-string
     */
    private static function nowNanos(): string
    {
        $now = new \DateTimeImmutable('now');
        return bcadd(bcmul((string) $now->getTimestamp(), (string) Nanos::PER_SECOND, 0), (string) ((int) $now->format('u') * 1000), 0);
    }

    /** Whether no part of a time, datetime, local date-time or duration is finer than `$unit`. */
    private static function hasResolution(mixed $value, mixed $unit): bool
    {
        if (!is_string($unit) || !array_key_exists($unit, self::RESOLUTIONS)) {
            return false;
        }
        $nanos = self::nanosOf($value);
        return $nanos !== null && $nanos % self::RESOLUTIONS[$unit] === 0;
    }

    /**
     * A time's, datetime's, local date-time's or duration's nanoseconds,
     * the part a resolution looks at; `null` for anything else.
     */
    private static function nanosOf(mixed $value): ?int
    {
        if ($value instanceof DXNDuration) {
            return $value->nanoseconds ?? 0;
        }
        $at = self::ordinal($value);
        return $at !== null && in_array($at[0], ['time', 'datetime', 'local'], true) && is_int($at[1]) ? $at[1] : null;
    }

    /** A collection's item count, `null` for a value that is none. */
    private static function countOf(mixed $value): ?int
    {
        // A map's, a shape's or a struct's count is its entries present,
        // extras included (T674: JSON Schema's minProperties and
        // maxProperties).
        return match (true) {
            $value instanceof Validated && $value->asRead !== null => self::countOf($value->asRead),
            $value instanceof Validated => self::countOf($value->value),
            $value instanceof DXNOrderedMap => count($value->pairs),
            $value instanceof DXNMap => count($value),
            $value instanceof DXNStruct => ($value->isKeyed() ? count($value->pairs()) : count($value->values())) + count($value->extra),
            is_array($value) && ($value === [] || !array_is_list($value)) => count($value),
            default => ($items = self::itemsOf($value)) === null ? null : count($items),
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

    /**
     * -1, 0 or 1, or `null` for two values that do not compare: NaN with
     * anything, or two values of different kinds.
     */
    private static function compare(mixed $a, mixed $b): ?int
    {
        $x = self::ordinal($a);
        $y = self::ordinal($b);
        if ($x === null || $y === null || $x[0] !== $y[0]) {
            return null;
        }
        [, $p] = $x;
        [, $q] = $y;
        if (is_float($p) || is_float($q)) {
            $p = (float) $p;
            $q = (float) $q;
            return is_nan($p) || is_nan($q) ? null : $p <=> $q;
        }
        return bccomp(self::numeric($p), self::numeric($q), 0);
    }

    /**
     * Whether `$value` has a place in some kind's order: a range's bound.
     *
     * @internal
     */
    public static function comparable(mixed $value): bool
    {
        return self::ordinal($value) !== null;
    }

    /**
     * Whether `$value` is a finite number other than zero: a `multiple-of`.
     *
     * @internal
     */
    public static function nonzeroNumber(mixed $value): bool
    {
        $at = self::ordinal($value);
        if ($at === null || $at[0] !== 'number') {
            return false;
        }
        $n = $at[1];
        return is_float($n) ? is_finite($n) && $n != 0.0 : bccomp(self::numeric($n), '0', 0) !== 0;
    }

    /**
     * Whether `$value` is a duration of a fixed length: a `within`.
     *
     * @internal
     */
    public static function span(mixed $value): bool
    {
        return (self::ordinal($value)[0] ?? null) === 'duration';
    }

    /**
     * A value as its kind and its place in that kind's order: a number
     * (an integer exactly, as an int or a big one's digits; anything else
     * as a float, NaN and the infinities included), or a date, time,
     * datetime, local date-time or duration as a count of days or
     * nanoseconds. A duration with years or months has no place: their
     * length varies. `null` for a value of no ordered kind.
     *
     * @return array{0: string, 1: int|float|string}|null
     */
    private static function ordinal(mixed $value): ?array
    {
        return match (true) {
            is_int($value), is_float($value) => ['number', $value],
            $value instanceof DXNInteger => ['number', $value->toString()],
            $value instanceof DXNDecimal, $value instanceof DXNRational => ['number', $value->toFloat()],
            $value instanceof DXNDate => ['date', Nanos::epochDays($value)],
            $value instanceof DXNTime => ['time', $value->nanosecondsSinceMidnight()],
            $value instanceof DXNDateTime => ['datetime', $value->epochNanoseconds],
            $value instanceof \DateTimeInterface => self::nativeOrdinal($value),
            $value instanceof DXNLocalDateTime => ['local', $value->localNanoseconds],
            $value instanceof DXNDuration => self::durationOrdinal($value),
            default => null,
        };
    }

    /**
     * A native datetime's place, `null` past 64 bits of nanoseconds.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function nativeOrdinal(\DateTimeInterface $value): ?array
    {
        try {
            return ['datetime', Nanos::ofNative($value)];
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A duration's total nanoseconds, a decimal string so no field's
     * size overflows it; `null` with years or months.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function durationOrdinal(DXNDuration $d): ?array
    {
        if (($d->years ?? 0) !== 0 || ($d->months ?? 0) !== 0) {
            return null;
        }
        $total = '0';
        foreach ([
            [$d->weeks, '604800000000000'],
            [$d->days, '86400000000000'],
            [$d->hours, '3600000000000'],
            [$d->minutes, '60000000000'],
            [$d->nanoseconds, '1'],
        ] as [$count, $unit]) {
            $total = bcadd($total, bcmul((string) ($count ?? 0), $unit, 0), 0);
        }
        return ['duration', $total];
    }

    /**
     * An integer, or a big one's digits, as the numeric string bcmath
     * takes.
     *
     * @return numeric-string
     */
    private static function numeric(int|float|string $n): string
    {
        $text = (string) $n;
        return is_numeric($text) ? $text : throw new \LogicException("unreachable: {$text} is no number");
    }
}
