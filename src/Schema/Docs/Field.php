<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema\Docs;

/**
 * One field of a schema or shape, as the documentation shows it.
 *
 * @internal
 */
final class Field
{
    public function __construct(
        public readonly string $name,
        public readonly bool $optional,
        public readonly ?At $type,
        public readonly ?At $default,
        public readonly ?string $description,
        public readonly string $doc,
        public readonly int $position,
    ) {
    }
}
