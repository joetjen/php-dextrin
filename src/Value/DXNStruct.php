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
 * @phpstan-type Pair array{0: string, 1: mixed}
 */
final class DXNStruct implements DXNValueInterface
{
    private const DXN_TYPE = 'struct';

    public readonly string $name;
    public readonly string $kind;
    /** @var list<Pair> */
    private readonly array $pairs;
    /** @var list<mixed> */
    private readonly array $values;

    /**
     * @param list<Pair> $pairs
     * @param list<mixed> $values
     */
    private function __construct(string $name, string $kind, array $pairs, array $values)
    {
        $this->name = $name;
        $this->kind = $kind;
        $this->pairs = $pairs;
        $this->values = $values;
    }

    /** @param array<Pair> $pairs */
    public static function keyed(string $name, array $pairs): self
    {
        return new self($name, 'keyed', array_values($pairs), []);
    }

    /** @param array<mixed> $values */
    public static function positional(string $name, array $values): self
    {
        return new self($name, 'positional', [], array_values($values));
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
        if (!$other instanceof self || $this->name !== $other->name || $this->kind !== $other->kind) {
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
