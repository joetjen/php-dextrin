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

    /**
     * The tuple `{:at Name N}`: `Name` pinned to version `N` (DXNS.md
     * §5c), which text writes `Name(N)`.
     *
     * @param string $name the name pinned
     * @param int $version its version, a positive integer
     * @return self the tuple
     */
    public static function pin(string $name, int $version): self
    {
        return new self([new DXNKeyword('at'), new DXNSymbol($name), $version]);
    }

    /**
     * The name and version a tuple `{:at Name N}` pins (DXNS.md §5c), which
     * text writes `Name(N)`: `[name, version]`, or `null` for any other
     * value. Shared by every writer, so the printer, the formatter and the
     * canonical form agree.
     *
     * ```php
     * DXNTuple::pinned(Dextrin::decode('Pet(2)')); // ['Pet', 2]
     * DXNTuple::pinned(new DXNTuple([1, 2]));      // null
     * ```
     *
     * @param mixed $value any value
     * @return array{0: string, 1: int}|null
     */
    public static function pinned(mixed $value): ?array
    {
        if (!$value instanceof self || count($value->items) !== 3) {
            return null;
        }
        [$at, $name, $version] = $value->items;
        return $at instanceof DXNKeyword && $at->name === 'at' && $name instanceof DXNSymbol && is_int($version) && $version > 0
            ? [$name->name, $version]
            : null;
    }

    /** How {@see pinned()}'s name and version are written: `Name(N)`. */
    public static function pinnedText(string $name, int $version): string
    {
        return "{$name}({$version})";
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
