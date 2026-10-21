<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema\Docs;

/**
 * One version of a definition: the file it is in, what that file says
 * about it, its fields, its value, and the annotations of a `{:doc}`
 * around it (DXNS.md §1b; `Docs::undoc()`).
 *
 * @internal
 */
final class Version
{
    /**
     * @param list<Field> $fields
     * @param array<string, mixed> $annotations
     */
    public function __construct(
        public readonly ?int $version,
        public readonly string $file,
        public readonly ?string $note,
        public readonly array $fields,
        public readonly ?At $value,
        public readonly array $annotations = [],
    ) {
    }
}
