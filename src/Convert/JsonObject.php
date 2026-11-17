<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Convert;

/**
 * A JSON object as {@see Json::decode()} reads it: its members in the
 * order written, each key a string. A PHP array would turn a key such as
 * `"1"` into an integer and could not tell `{}` from `[]`; the JSON Schema
 * converter needs both the order (properties become fields in it) and
 * the difference.
 *
 * A key written twice keeps its first place and its last value.
 */
final class JsonObject implements \Countable
{
    /** @var list<string> the keys, in the order first written */
    private array $keys = [];

    /** @var array<string, mixed> each value, by `"k" . key` (so no key turns into an integer) */
    private array $values = [];

    /**
     * An object of `$pairs`, in their order.
     *
     * @param iterable<array{0: string, 1: mixed}> $pairs
     */
    public static function of(iterable $pairs): self
    {
        $object = new self();
        foreach ($pairs as [$key, $value]) {
            $object->set($key, $value);
        }
        return $object;
    }

    /** Sets `$key` to `$value`: a new key goes last, a known one keeps its place. */
    public function set(string $key, mixed $value): void
    {
        if (!array_key_exists('k' . $key, $this->values)) {
            $this->keys[] = $key;
        }
        $this->values['k' . $key] = $value;
    }

    /** Whether the object has the member `$key`. */
    public function has(string $key): bool
    {
        return array_key_exists('k' . $key, $this->values);
    }

    /** The value of `$key`, or `null` when there is none (or it is `null`). */
    public function get(string $key): mixed
    {
        return $this->values['k' . $key] ?? null;
    }

    /**
     * The keys, in the order written.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return $this->keys;
    }

    /**
     * The members, in the order written.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public function pairs(): array
    {
        return array_map(fn (string $key): array => [$key, $this->values['k' . $key]], $this->keys);
    }

    /** This object without `$keys`, the rest in their order. */
    public function without(string ...$keys): self
    {
        return self::of(array_filter($this->pairs(), static fn (array $pair): bool => !in_array($pair[0], $keys, true)));
    }

    public function count(): int
    {
        return count($this->keys);
    }
}
