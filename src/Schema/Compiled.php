<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * The result of compiling one `%schema{}` entry from a `.dxns`
 * document ({@see Compiler}) -- enough to both validate a decoded
 * value and convert it both directions between `.dxnb`'s
 * always-positional wire shape and a named field map.
 *
 * `fields` is ordered -- that order is the canonical position <-> name
 * mapping `.dxnb`'s positional struct encoding needs, which is exactly
 * why a schema's own `fields:` has to be an `@ordered %{...}` in the
 * source document, not a plain `map` (`.dxn`'s `map` type makes no
 * ordering guarantee, but field order here is semantically
 * load-bearing, not incidental).
 *
 * @phpstan-type RefineFn \Closure(array<string, mixed>): (bool|string)
 */
final class Compiled
{
    /**
     * @param list<Field> $fields
     * @param list<string> $forbidden
     * @param RefineFn|null $refineFn `resolved` is this schema's own
     * already-type-checked field values; return `true` to accept the
     * value, or a `string` describing why not.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly array $fields = [],
        public readonly bool $closed = false,
        public readonly array $forbidden = [],
        public readonly ?\Closure $refineFn = null,
    ) {
    }

    /** A schema's `name` is only known once {@see Compiler} finishes compiling its enclosing document -- returns a copy with it set. */
    public function withName(string $name): self
    {
        return new self($name, $this->fields, $this->closed, $this->forbidden, $this->refineFn);
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_map(static fn (Field $f): string => $f->name, $this->fields);
    }

    /**
     * Reads `$instance`'s fields (an arbitrary registered PHP class
     * instance, not a {@see \JOetjen\Dextrin\Value\DXNStruct}) out in
     * this schema's own canonical field order -- the same order
     * `.dxnb`'s positional wire encoding needs. A field this schema
     * declares but `$instance` doesn't have is `null`, same as any
     * other absent field; extra instance properties the schema doesn't
     * know about are simply not included.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public function fieldValues(object $instance): array
    {
        $vars = get_object_vars($instance);
        return array_map(static fn (string $name): array => [$name, $vars[$name] ?? null], $this->fieldNames());
    }
}
