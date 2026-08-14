<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * A payload-free enum used as {@see Coercion::tryCoerce()}'s "no
 * applicable coercion" sentinel, comparable via `!==`/`instanceof` --
 * a coerced value can legitimately be `null` (a value coerced toward
 * a `nilable` field, say), so `null` itself can't double as "coercion
 * didn't apply" the way JS's own `tryCoerce` uses `undefined`.
 */
enum NotCoercible
{
    case Instance;
}
