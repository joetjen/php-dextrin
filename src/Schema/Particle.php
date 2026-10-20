<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * One of a `{:list-items}`'s items (DXN.md §4.1a): a type for exactly one
 * item, or a repeat of a group of them, `$max` `null` when there is no
 * upper bound.
 */
final class Particle
{
    /** @param list<self>|null $group */
    private function __construct(
        public readonly ?TypeExpr $type,
        public readonly ?array $group,
        public readonly int $min,
        public readonly ?int $max,
    ) {
    }

    /** Exactly one item, a `$type`. */
    public static function one(TypeExpr $type): self
    {
        return new self($type, null, 1, 1);
    }

    /**
     * The `$group`'s items, together, between `$min` and `$max` times.
     *
     * @param list<self> $group
     */
    public static function repeat(array $group, int $min, ?int $max): self
    {
        return new self(null, $group, $min, $max);
    }

    /**
     * This particle with every type in it replaced by what `$map` makes of it.
     *
     * @param \Closure(TypeExpr): TypeExpr $map
     */
    public function map(\Closure $map): self
    {
        if ($this->type !== null) {
            return self::one($map($this->type));
        }
        return self::repeat(array_map(static fn (self $p): self => $p->map($map), $this->group ?? []), $this->min, $this->max);
    }

    /**
     * Every type in this particle, in order.
     *
     * @return list<TypeExpr>
     */
    public function types(): array
    {
        if ($this->type !== null) {
            return [$this->type];
        }
        return array_merge([], ...array_map(static fn (self $p): array => $p->types(), $this->group ?? []));
    }
}
