<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `ordered-map` (`@ordered %{...}`) -- order is part of value
 * identity. Wrapped rather than a bare associative PHP array (which
 * already claims `map`, the no-ordering-guarantee sibling) so the two
 * stay distinguishable on encode; also because a DXN map/ordered-map
 * key can be any DXN value, not just an `int|string` the way a native
 * PHP array key must be.
 *
 * @phpstan-type Pair array{0: mixed, 1: mixed}
 */
final class DXNOrderedMap implements DXNValueInterface
{
    private const DXN_TYPE = 'ordered-map';

    /** @var list<Pair> */
    public readonly array $pairs;

    /** @param array<Pair> $pairs */
    public function __construct(array $pairs)
    {
        $this->pairs = array_values($pairs);
    }

    /**
     * Converts to an associative PHP array -- only lossless when
     * every key is already an `int|string` (a DXN map/ordered-map key
     * can be any DXN value); throws otherwise.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->pairs as [$key, $value]) {
            if (!is_int($key) && !is_string($key)) {
                throw new \InvalidArgumentException('toArray() requires every key to be int|string; got ' . Equality::kindOf($key));
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /** @param array<int|string, mixed> $map */
    public static function fromArray(array $map): self
    {
        $pairs = [];
        foreach ($map as $key => $value) {
            $pairs[] = [$key, $value];
        }
        return new self($pairs);
    }

    public function compareTo(mixed $other): int
    {
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        if (!$other instanceof self || count($this->pairs) !== count($other->pairs)) {
            return false;
        }
        foreach ($this->pairs as $i => [$key, $value]) {
            [$otherKey, $otherValue] = $other->pairs[$i];
            if (!Equality::dxnEquals($key, $otherKey) || !Equality::dxnEquals($value, $otherValue)) {
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
