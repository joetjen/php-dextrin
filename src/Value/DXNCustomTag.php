<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `custom-tag` (`@tag value`) -- the open extension point, no
 * decoder registered for `name`.
 */
final class DXNCustomTag implements DXNValueInterface
{
    private const DXN_TYPE = 'custom-tag';

    public readonly string $name;
    public readonly mixed $value;

    public function __construct(string $name, mixed $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function compareTo(mixed $other): int
    {
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->name === $other->name && Equality::dxnEquals($this->value, $other->value);
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
