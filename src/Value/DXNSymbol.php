<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `symbol` (a bare identifier, e.g. `foo`) -- an unevaluated
 * reference, e.g. a type name. Always wraps: PHP has no "evaluated
 * later" bare-identifier native type, and no interning table whose
 * exhaustion decoding untrusted input would need to guard against
 * either way.
 */
final class DXNSymbol implements DXNValueInterface
{
    private const DXN_TYPE = 'symbol';

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
