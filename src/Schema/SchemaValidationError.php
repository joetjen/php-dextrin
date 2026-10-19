<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\HasCategory;

/**
 * Thrown by {@see Validator}/{@see \JOetjen\Dextrin\Schema} for a value
 * that violates its schema, with the violation's DXN.md §5 category
 * (`schema/missing-field`, ...), which every channel passes on
 * unchanged. Null only where a caller asked for something that is not a
 * DXN error at all, such as a schema that was never registered.
 */
final class SchemaValidationError extends \RuntimeException implements HasCategory
{
    public function __construct(string $message, private readonly ?string $category = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function category(): ?string
    {
        return $this->category;
    }
}
