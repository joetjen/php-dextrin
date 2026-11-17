<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * Which deprecated things have warned, for `deprecations: 'once'`
 * ({@see Deprecations}): held by a {@see \JOetjen\Dextrin\Registry} and
 * shared by every copy of it, so "once" outlives a single decode. It
 * lives as long as a registry refers to it; nothing else owns it.
 *
 * @internal
 */
final class DeprecationsSeen
{
    /** @var array<string, true> */
    private array $seen = [];

    /** Whether `$key` is used here for the first time; it is seen from now on. */
    public function firstUse(string $key): bool
    {
        if (isset($this->seen[$key])) {
            return false;
        }
        $this->seen[$key] = true;
        return true;
    }
}
