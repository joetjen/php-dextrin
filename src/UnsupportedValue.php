<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

/**
 * A value with no DXN meaning -- a closure, a resource -- refused by the
 * printer and the binary encoder alike, with DXN.md §5's
 * `encode/unsupported` category.
 */
final class UnsupportedValue extends \InvalidArgumentException implements HasCategory
{
    public static function of(mixed $value): self
    {
        return new self('cannot encode value with no DXN representation: ' . get_debug_type($value));
    }

    public function category(): string
    {
        return 'encode/unsupported';
    }
}
