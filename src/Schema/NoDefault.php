<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * A payload-free enum used purely as a unique sentinel value ({@see
 * Field::NO_DEFAULT}), comparable via `!==` -- PHP has no `undefined`/
 * `Symbol()` to reach for the way JS's own `Field.NO_DEFAULT` does,
 * and a plain `null` would be ambiguous with a field whose declared
 * default genuinely *is* `null` (DXN `nil`).
 */
enum NoDefault
{
    case Instance;
}
