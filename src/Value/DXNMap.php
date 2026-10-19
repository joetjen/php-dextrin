<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

use JOetjen\Dextrin\Canonical;

/**
 * A DXN map that a native PHP array cannot hold.
 *
 * A map decodes to a native array whenever it can: every key an integer,
 * a keyword or a string, no two of them colliding once PHP coerces them
 * to array keys, and not shaped like a list (which would read back as a
 * DXN array). Anything else -- a char, uuid, date, bytes or symbol key,
 * `1` beside `"1"`, an empty map -- is one of these.
 *
 * **Keys are compared by value** (DXN.md §1.6): two keys are the same key
 * exactly when their canonical forms are. A repeated key keeps its last
 * value and its first key object.
 *
 * @implements \IteratorAggregate<mixed, mixed>
 */
final class DXNMap implements DXNValueInterface, \Countable, \IteratorAggregate
{
    private const DXN_TYPE = 'map';

    /** @var array<string, array{0: mixed, 1: mixed}> identity => [key, value] */
    private array $entries = [];

    /** @param iterable<array{0: mixed, 1: mixed}> $pairs */
    public function __construct(iterable $pairs = [])
    {
        foreach ($pairs as [$key, $value]) {
            $identity = Canonical::print($key);
            $held = array_key_exists($identity, $this->entries) ? $this->entries[$identity][0] : $key;
            $this->entries[$identity] = [$held, $value];
        }
    }

    /** @return list<array{0: mixed, 1: mixed}> */
    public function pairs(): array
    {
        return array_values($this->entries);
    }

    public function has(mixed $key): bool
    {
        return array_key_exists(Canonical::print($key), $this->entries);
    }

    public function get(mixed $key, mixed $default = null): mixed
    {
        $identity = Canonical::print($key);
        return array_key_exists($identity, $this->entries) ? $this->entries[$identity][1] : $default;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return \Generator<mixed, mixed> */
    public function getIterator(): \Generator
    {
        foreach ($this->entries as [$key, $value]) {
            yield $key => $value;
        }
    }

    public function compareTo(mixed $other): int
    {
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        if (!$other instanceof self || count($this) !== count($other)) {
            return false;
        }
        foreach ($this->entries as $identity => [, $value]) {
            if (!array_key_exists($identity, $other->entries) || !Equality::dxnEquals($value, $other->entries[$identity][1])) {
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
