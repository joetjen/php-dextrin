<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `list` (`[1 2 3]`) -- wrapped so it stays distinct from `array`
 * (`@array[...]`), which claims the bare sequential-PHP-array shape
 * instead (see the project's collections decision: PHP's `array`
 * only offers one structural fork, sequential-vs-associative, so it
 * can't safely host two DXN types).
 */
final class DXNList implements DXNValueInterface
{
    private const DXN_TYPE = 'list';

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
