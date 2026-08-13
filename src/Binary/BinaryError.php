<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Binary;

use JOetjen\Dextrin\HasOffset;

/** Error type for malformed `.dxnb` input -- the binary-side analog of `Text\ParseError`. */
final class BinaryError extends \RuntimeException implements HasOffset
{
    private readonly ?int $byteOffset;

    public function __construct(string $message, ?int $byteOffset = null)
    {
        parent::__construct($message);
        $this->byteOffset = $byteOffset;
    }

    public function offset(): ?int
    {
        return $this->byteOffset;
    }
}
