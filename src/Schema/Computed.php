<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * A field's default that is computed when the field is filled, not
 * written: `%field{type: :timestamp, computed: :now}` (DXNS.md §4; G8 of
 * dextrin's design/typespec.md). {@see Field::filled()} turns it into
 * the value -- the moment it is asked, in UTC -- wherever a written
 * default would be filled.
 *
 * `$kind` is the temporal type it fills: `date`, `time`, `datetime` or
 * `local-datetime`. `now` is the one computation there is.
 */
final class Computed
{
    /** @param 'date'|'time'|'datetime'|'local-datetime' $kind */
    public function __construct(public readonly string $kind)
    {
    }
}
