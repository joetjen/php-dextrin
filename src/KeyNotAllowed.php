<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

/**
 * A map key that DXN.md §1.6 does not allow, with its `key/not-allowed`
 * category.
 */
final class KeyNotAllowed extends \InvalidArgumentException implements HasCategory, HasOffset
{
    private ?int $at = null;

    /**
     * Refuses `$key`, named as DXN writes it (`[1] cannot be a map key`),
     * as the reference names it, or as PHP does when it has no DXN form.
     */
    public static function of(mixed $key): self
    {
        try {
            $described = Text\Printer::print($key);
        } catch (\Throwable) {
            $described = is_scalar($key) || $key === null ? var_export($key, true) : get_debug_type($key);
        }
        return new self("{$described} cannot be a map key: a key is a string, keyword, integer, uuid, objectid, date, bytes, symbol or char");
    }

    /**
     * This refusal, placed at `$offset`, where the key was written -- the
     * first place found wins.
     */
    public function placedAt(int $offset): self
    {
        $this->at ??= $offset;
        return $this;
    }

    public function offset(): ?int
    {
        return $this->at;
    }

    public function category(): string
    {
        return 'key/not-allowed';
    }
}
