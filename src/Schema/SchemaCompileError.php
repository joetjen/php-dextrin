<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\HasCategory;

/** Thrown by {@see Compiler} for a malformed `.dxns` document, with the `schema/compile` category. */
final class SchemaCompileError extends \RuntimeException implements HasCategory
{
    public function category(): string
    {
        return 'schema/compile';
    }
}
