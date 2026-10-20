<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema\Docs;

/**
 * One version of a definition: the file it is in, what that file says
 * about it, its fields, and its value.
 *
 * @internal
 */
final class Version
{
    /** @param list<Field> $fields */
    public function __construct(
        public readonly ?int $version,
        public readonly string $file,
        public readonly ?string $note,
        public readonly array $fields,
        public readonly ?At $value,
    ) {
    }
}
