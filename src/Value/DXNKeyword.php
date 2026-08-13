<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `keyword` (`:foo` / `foo:` in key position) -- always wraps in
 * this port, with no `trusted:`-driven alternate representation the
 * way the Elixir (real atom) and JS (`Symbol.for`) ports have. PHP
 * has nothing analogous to a BEAM atom table or `Symbol.for`'s global
 * registry: every `DXNKeyword` is an ordinary heap-allocated, GC'd
 * object, so there's no exhaustible interning table decoding
 * untrusted input could ever threaten in the first place -- this is
 * a deliberate omission, not a missing feature.
 */
final class DXNKeyword implements DXNValueInterface
{
    private const DXN_TYPE = 'keyword';

    public readonly string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return $this->name <=> $other->name;
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->name === $other->name;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
