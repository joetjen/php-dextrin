<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `set` (`@{...}`) -- deduplicated at construction, structural
 * equality, order-independent.
 */
final class DXNSet implements DXNValueInterface
{
    private const DXN_TYPE = 'set';

    /** @var list<mixed> */
    public readonly array $items;

    /** @param array<mixed> $items */
    public function __construct(array $items)
    {
        $this->items = self::dedupe(array_values($items));
    }

    /**
     * @param list<mixed> $items
     * @return list<mixed>
     */
    private static function dedupe(array $items): array
    {
        $result = [];
        $seen = [];
        foreach ($items as $item) {
            $key = Equality::canonicalKey($item);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $item;
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

    public function compareTo(mixed $other): int
    {
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        if (!$other instanceof self || count($this->items) !== count($other->items)) {
            return false;
        }
        $ownKeys = array_map(Equality::canonicalKey(...), $this->items);
        $otherKeys = array_map(Equality::canonicalKey(...), $other->items);
        sort($ownKeys);
        sort($otherKeys);
        return $ownKeys === $otherKeys;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
