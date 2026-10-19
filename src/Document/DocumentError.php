<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Document;

use JOetjen\Dextrin\HasCategory;

/**
 * Why a {@see \JOetjen\Dextrin\Document} edit was refused: the path led
 * nowhere (`document/not-found`), to no collection
 * (`document/not-a-collection`), to a key already there
 * (`document/exists`), or an update could not tell which edit was meant
 * without risking a comment (`document/ambiguous`).
 */
final class DocumentError extends \RuntimeException implements HasCategory
{
    /** @param list<mixed> $path */
    public function __construct(public readonly string $reason, public readonly array $path)
    {
        parent::__construct("{$reason}: " . implode(' ', array_map(static fn (mixed $step): string => get_debug_type($step) === 'string' || is_int($step) ? (string) $step : get_debug_type($step), $path)));
    }

    public function category(): string
    {
        return "document/{$this->reason}";
    }
}
