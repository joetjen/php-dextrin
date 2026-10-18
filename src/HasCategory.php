<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

/**
 * Implemented by any exception that knows its DXN.md §5 category --
 * `syntax`, `binary`, `encode/unsupported`, ... -- so
 * {@see DXNError::wrap()} can carry it across without knowing every
 * concrete exception class, the way {@see HasOffset} carries an offset.
 */
interface HasCategory
{
    /** The category, or null for an exception that has none. */
    public function category(): ?string;
}
