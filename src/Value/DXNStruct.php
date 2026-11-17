<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `struct` (`%Name{...}` / `%Name[...]`), opaque without a
 * schema. Keeps the keyed/positional shapes distinct rather than
 * normalizing to one, same as the Elixir and JS ports -- `.dxnb` is
 * always positional and can't recover field names without a schema.
 *
 * This is decode's default representation for an unregistered struct
 * name; on encode, any plain PHP object with public properties is
 * also accepted directly (see {@see Equality::kindOf()}) without
 * needing to be built into a `DXNStruct` by hand first.
 *
 * `version` is the schema version the struct was written with --
 * `%Name(3){...}`, DXNS.md §8 -- or `null` when it was written
 * without one.
 *
 * `extra` holds the fields a struct carries beyond its declared ones
 * (DXNS.md §7), as `[key, value]` pairs in the order they were written:
 * a name for a named field, any other map key (§1.6) as itself. In text
 * they are entries after the fields or the items; in `.dxnb`, one
 * trailing tag-215 map.
 *
 * `bases` and `drops` are what a `%schema` or `%shape` is built from
 * (DXNS.md §4b): `bases` the head's `extends` and `includes` names, as
 * `[kind, name, version]` in the order written -- kind `'extends'` or
 * `'includes'`, version `null` when none is named -- and `drops` the
 * body's `- field` names. Every other struct has none.
 *
 * @phpstan-type Pair array{0: string, 1: mixed}
 * @phpstan-type Base array{0: 'extends'|'includes', 1: string, 2: ?int}
 */
final class DXNStruct implements DXNValueInterface
{
    private const DXN_TYPE = 'struct';

    public readonly string $name;
    public readonly string $kind;
    public readonly ?int $version;
    /** @var list<array{0: mixed, 1: mixed}> */
    public readonly array $extra;
    /** @var list<Pair> */
    private readonly array $pairs;
    /** @var list<mixed> */
    private readonly array $values;
    /** @var list<Base> */
    public readonly array $bases;
    /** @var list<string> */
    public readonly array $drops;

    /**
     * @param list<Pair> $pairs
     * @param list<mixed> $values
     * @param list<array{0: mixed, 1: mixed}> $extra
     * @param list<Base> $bases
     * @param list<string> $drops
     */
    private function __construct(
        string $name,
        string $kind,
        array $pairs,
        array $values,
        ?int $version,
        array $extra,
        array $bases = [],
        array $drops = [],
    ) {
        $this->name = $name;
        $this->kind = $kind;
        $this->pairs = $pairs;
        $this->values = $values;
        $this->version = $version;
        $this->extra = $extra;
        $this->bases = $bases;
        $this->drops = $drops;
    }

    /**
     * @param array<Pair> $pairs
     * @param array<array{0: mixed, 1: mixed}> $extra
     */
    public static function keyed(string $name, array $pairs, ?int $version = null, array $extra = []): self
    {
        return new self($name, 'keyed', array_values($pairs), [], $version, array_values($extra));
    }

    /**
     * @param array<mixed> $values
     * @param array<array{0: mixed, 1: mixed}> $extra
     */
    public static function positional(string $name, array $values, ?int $version = null, array $extra = []): self
    {
        return new self($name, 'positional', [], array_values($values), $version, array_values($extra));
    }

    /**
     * An entry's key as an extra field's key: a keyword, symbol or string
     * is a name (its text); any other map key (DXN.md §1.6) is kept as
     * itself.
     */
    public static function extraKey(mixed $key): mixed
    {
        return match (true) {
            $key instanceof DXNKeyword, $key instanceof DXNSymbol => $key->name,
            default => $key,
        };
    }

    /**
     * How a struct literal opens: `%Name`, or `%Name(3)` when it carries
     * a version. Shared by every writer, so they agree.
     */
    public static function head(string $name, ?int $version = null): string
    {
        return '%' . self::typeName($name, $version);
    }

    /**
     * The struct's name as `.dxnb` writes it: `Name`, or `Name(3)` when
     * it carries a version (DXN.md §2.4, §4.8).
     */
    public static function typeName(string $name, ?int $version = null): string
    {
        return $version === null ? $name : "{$name}({$version})";
    }

    /**
     * The other direction: a `.dxnb` type name read back into its name
     * and version, or `null` when its version is not a positive integer.
     *
     * @return array{0: string, 1: ?int}|null
     */
    public static function parseTypeName(string $text): ?array
    {
        if (!str_contains($text, '(') && !str_contains($text, ')')) {
            return [$text, null];
        }
        if (preg_match('/\A([^()]+)\(([0-9]+)\)\z/s', $text, $match) !== 1) {
            return null;
        }
        $version = filter_var($match[2], FILTER_VALIDATE_INT);
        return $version === false || $version <= 0 ? null : [$match[1], $version];
    }

    /** This struct carrying `$version` instead, and otherwise unchanged. */
    public function withVersion(?int $version): self
    {
        return new self($this->name, $this->kind, $this->pairs, $this->values, $version, $this->extra, $this->bases, $this->drops);
    }

    /**
     * This struct built from `$bases`, leaving out `$drops` (DXNS.md §4b),
     * and otherwise unchanged -- what the parser makes of a `%schema` or
     * `%shape` head's clauses and its body's `- field`s.
     *
     * @param list<Base> $bases
     * @param list<string> $drops
     */
    public function withBases(array $bases, array $drops = []): self
    {
        return new self($this->name, $this->kind, $this->pairs, $this->values, $this->version, $this->extra, $bases, $drops);
    }

    /** Whether this is a `%schema` or `%shape` built from others (DXNS.md §4b). */
    public function isBuilt(): bool
    {
        return $this->bases !== [] || $this->drops !== [];
    }

    /**
     * How this struct opens when written at `$version`: its {@see head()},
     * then the `extends` and `includes` clauses a `%schema` or `%shape` is
     * built from (DXNS.md §4b), in the order the bases were written, and
     * one space before the body. Shared by every text writer.
     *
     * `%schema(2) extends User(3) includes Audit ` for a schema built
     * from those two.
     */
    public function opening(?int $version): string
    {
        $head = self::head($this->name, $version);
        if ($this->bases === []) {
            return $head;
        }
        $clauses = [];
        $kind = null;
        foreach ($this->bases as [$baseKind, $base, $baseVersion]) {
            if ($baseKind !== $kind) {
                $clauses[] = $baseKind;
                $kind = $baseKind;
            }
            $clauses[] = self::typeName($base, $baseVersion);
        }
        return $head . ' ' . implode(' ', $clauses) . ' ';
    }

    /**
     * The `- field` entries of a `%schema` or `%shape` that leaves
     * inherited fields out (DXNS.md §4b), as they are written.
     *
     * @return list<string>
     */
    public function dropEntries(): array
    {
        return array_map(static fn (string $field): string => "- {$field}", $this->drops);
    }

    /**
     * This struct holding `$pairs` instead, and otherwise unchanged --
     * what every walk over a keyed struct's fields goes through, so a
     * walk never rebuilds a struct from its name and loses its version.
     *
     * @param array<Pair> $pairs
     */
    public function withPairs(array $pairs): self
    {
        return new self($this->name, 'keyed', array_values($pairs), [], $this->version, $this->extra, $this->bases, $this->drops);
    }

    /**
     * This struct holding `$values` instead, and otherwise unchanged --
     * the positional counterpart of {@see withPairs()}.
     *
     * @param array<mixed> $values
     */
    public function withValues(array $values): self
    {
        return new self($this->name, 'positional', [], array_values($values), $this->version, $this->extra, $this->bases, $this->drops);
    }

    public function isKeyed(): bool
    {
        return $this->kind === 'keyed';
    }

    public function isPositional(): bool
    {
        return $this->kind === 'positional';
    }

    /** @return list<Pair> */
    public function pairs(): array
    {
        if (!$this->isKeyed()) {
            throw new \LogicException("struct \"{$this->name}\" is positional, not keyed");
        }
        return $this->pairs;
    }

    /** @return list<mixed> */
    public function values(): array
    {
        if (!$this->isPositional()) {
            throw new \LogicException("struct \"{$this->name}\" is keyed, not positional");
        }
        return $this->values;
    }

    public function compareTo(mixed $other): int
    {
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        if (
            !$other instanceof self
            || $this->name !== $other->name
            || $this->kind !== $other->kind
            || $this->version !== $other->version
            || $this->bases !== $other->bases
            || $this->drops !== $other->drops
            || count($this->extra) !== count($other->extra)
        ) {
            return false;
        }
        foreach ($this->extra as $i => [$key, $value]) {
            if (!Equality::dxnEquals($key, $other->extra[$i][0]) || !Equality::dxnEquals($value, $other->extra[$i][1])) {
                return false;
            }
        }
        if ($this->isPositional()) {
            if (count($this->values) !== count($other->values)) {
                return false;
            }
            foreach ($this->values as $i => $value) {
                if (!Equality::dxnEquals($value, $other->values[$i])) {
                    return false;
                }
            }
            return true;
        }
        // Keyed fields are conceptually a map -- order isn't part of
        // identity here the way it is for `positional` (which has no
        // field names to key by instead).
        if (count($this->pairs) !== count($other->pairs)) {
            return false;
        }
        $ownByKey = [];
        foreach ($this->pairs as [$key, $value]) {
            $ownByKey[$key] = $value;
        }
        $otherByKey = [];
        foreach ($other->pairs as [$key, $value]) {
            $otherByKey[$key] = $value;
        }
        foreach ($ownByKey as $key => $value) {
            if (!array_key_exists($key, $otherByKey) || !Equality::dxnEquals($value, $otherByKey[$key])) {
                return false;
            }
        }
        return true;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
