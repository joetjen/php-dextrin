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
 * `%Name(3){...}`, DXN.md §4.8 -- or `null` when it was written
 * without one.
 *
 * @phpstan-type Pair array{0: string, 1: mixed}
 */
final class DXNStruct implements DXNValueInterface
{
    private const DXN_TYPE = 'struct';

    public readonly string $name;
    public readonly string $kind;
    public readonly ?int $version;
    /** @var list<Pair> */
    private readonly array $pairs;
    /** @var list<mixed> */
    private readonly array $values;

    /**
     * @param list<Pair> $pairs
     * @param list<mixed> $values
     */
    private function __construct(string $name, string $kind, array $pairs, array $values, ?int $version)
    {
        $this->name = $name;
        $this->kind = $kind;
        $this->pairs = $pairs;
        $this->values = $values;
        $this->version = $version;
    }

    /** @param array<Pair> $pairs */
    public static function keyed(string $name, array $pairs, ?int $version = null): self
    {
        return new self($name, 'keyed', array_values($pairs), [], $version);
    }

    /** @param array<mixed> $values */
    public static function positional(string $name, array $values, ?int $version = null): self
    {
        return new self($name, 'positional', [], array_values($values), $version);
    }

    /**
     * How a struct literal opens: `%Name`, or `%Name(3)` when it carries
     * a version. Shared by every writer, so they agree.
     */
    public static function head(string $name, ?int $version = null): string
    {
        return $version === null ? "%{$name}" : "%{$name}({$version})";
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
        return self::keyed($this->name, $pairs, $this->version);
    }

    /**
     * This struct holding `$values` instead, and otherwise unchanged --
     * the positional counterpart of {@see withPairs()}.
     *
     * @param array<mixed> $values
     */
    public function withValues(array $values): self
    {
        return self::positional($this->name, $values, $this->version);
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
        ) {
            return false;
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
