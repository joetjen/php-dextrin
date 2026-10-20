<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Registry;

/**
 * What a check knows besides the type and the value: the registry
 * (`null` when there is none -- strict is then off), and the fields of
 * the nearest enclosing struct or shape by name, which a `{:case}` reads
 * its sibling from (`null` outside any).
 */
final class Context
{
    /** @param array<string, mixed>|null $siblings */
    public function __construct(
        public readonly ?Registry $registry = null,
        public readonly ?array $siblings = null,
    ) {
    }
}
