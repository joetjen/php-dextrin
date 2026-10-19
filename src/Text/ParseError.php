<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\HasOffset;

final class ParseError extends \RuntimeException implements HasOffset, HasCategory
{
    private readonly ?int $codepointOffset;

    public function __construct(string $message, ?int $codepointOffset = null)
    {
        parent::__construct($message);
        $this->codepointOffset = $codepointOffset;
    }

    public function offset(): ?int
    {
        return $this->codepointOffset;
    }

    /** DXN.md §5's category for text that does not parse. */
    public function category(): string
    {
        return 'syntax';
    }
}
