<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `sorted-set` (`@sorted-set @{...}`) -- sorted and deduplicated
 * at every construction site, same invariant enforced in the Elixir
 * and JS ports. Cross-type ordering is implementation-defined per
 * DXN.md; this port's fixed rank order is {@see Equality}'s own.
 */
final class DXNSortedSet implements DXNValueInterface
{
    private const DXN_TYPE = 'sorted-set';

    /** @var list<mixed> */
    public readonly array $items;

    /** @param array<mixed> $items */
    public function __construct(array $items)
    {
        $this->items = self::sortAndDedupe(array_values($items));
    }

    /**
     * @param list<mixed> $items
     * @return list<mixed>
     */
    private static function sortAndDedupe(array $items): array
    {
        usort($items, Equality::dxnCompare(...));
        $result = [];
        $seenKey = null;
        foreach ($items as $item) {
            $key = Equality::canonicalKey($item);
            if ($key !== $seenKey) {
                $result[] = $item;
                $seenKey = $key;
            }
        }
        return $result;
    }

    /** @return list<mixed> */
    public function toArray(): array
    {
        return $this->items;
    }

    /** @param array<mixed> $items */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    public function toSet(): DXNSet
    {
        return new DXNSet($this->items);
    }

    public static function fromSet(DXNSet $set): self
    {
        return new self($set->items);
    }

    public function compareTo(mixed $other): int
    {
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        if (!$other instanceof self || count($this->items) !== count($other->items)) {
            return false;
        }
        foreach ($this->items as $i => $item) {
            if (!Equality::dxnEquals($item, $other->items[$i])) {
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
