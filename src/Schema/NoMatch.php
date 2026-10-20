<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * What {@see TypeExpr::conform()} answers when a value does not match:
 * a value of its own, since `null` and `false` are values a type takes.
 */
enum NoMatch
{
    case Instance;
}
