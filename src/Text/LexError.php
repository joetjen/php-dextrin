<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

use JOetjen\Dextrin\HasOffset;

final class LexError extends \RuntimeException implements HasOffset
{
    private readonly int $codepointOffset;

    public function __construct(string $message, int $codepointOffset)
    {
        parent::__construct($message);
        $this->codepointOffset = $codepointOffset;
    }

    public function offset(): int
    {
        return $this->codepointOffset;
    }
}
