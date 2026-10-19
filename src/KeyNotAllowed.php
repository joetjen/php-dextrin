<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

/**
 * A map key that DXN.md §1.6 does not allow, with its `key/not-allowed`
 * category.
 */
final class KeyNotAllowed extends \InvalidArgumentException implements HasCategory
{
    public static function of(mixed $key): self
    {
        $described = is_scalar($key) || $key === null ? var_export($key, true) : get_debug_type($key);
        return new self("{$described} cannot be a map key: a key is a string, keyword, integer, uuid, date, bytes, symbol or char");
    }

    public function category(): string
    {
        return 'key/not-allowed';
    }
}
