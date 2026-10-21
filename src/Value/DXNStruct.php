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
 * @phpstan-type Pair array{0: string, 1: mixed}
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

    /**
     * @param list<Pair> $pairs
     * @param list<mixed> $values
     * @param list<array{0: mixed, 1: mixed}> $extra
     */
    private function __construct(string $name, string $kind, array $pairs, array $values, ?int $version, array $extra)
    {
        $this->name = $name;
        $this->kind = $kind;
        $this->pairs = $pairs;
        $this->values = $values;
        $this->version = $version;
        $this->extra = $extra;
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
        return new self($this->name, $this->kind, $this->pairs, $this->values, $version, $this->extra);
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
        return self::keyed($this->name, $pairs, $this->version, $this->extra);
    }

    /**
     * This struct holding `$values` instead, and otherwise unchanged --
     * the positional counterpart of {@see withPairs()}.
     *
     * @param array<mixed> $values
     */
    public function withValues(array $values): self
    {
        return self::positional($this->name, $values, $this->version, $this->extra);
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
