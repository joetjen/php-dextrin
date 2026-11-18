<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema\Docs;

/**
 * One field of a schema or shape, as the documentation shows it: its
 * default and computed default as written, and its `annotations`, those
 * of a `{:doc}` around its type (`Docs::undoc()`).
 *
 * @internal
 */
final class Field
{
    /** @param array<string, mixed> $annotations */
    public function __construct(
        public readonly string $name,
        public readonly bool $optional,
        public readonly ?At $type,
        public readonly ?At $default,
        public readonly ?At $computed,
        public readonly ?string $description,
        public readonly string $doc,
        public readonly int $position,
        public readonly array $annotations = [],
    ) {
    }
}
