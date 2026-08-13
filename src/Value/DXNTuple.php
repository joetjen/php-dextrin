<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/** DXN `tuple` (`{1 2 3}`) -- list-backed, arbitrary length. */
final class DXNTuple implements DXNValueInterface
{
    private const DXN_TYPE = 'tuple';

    /** @var list<mixed> */
    public readonly array $items;

    /** @param array<mixed> $items */
    public function __construct(array $items)
    {
        $this->items = array_values($items);
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
