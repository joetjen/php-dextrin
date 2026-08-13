<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

/**
 * Implemented by any exception that can report a byte/character
 * offset into the source it failed on (lexer/parser errors) -- lets
 * {@see DXNError::wrap()} preserve that offset without needing to
 * know about every concrete exception class in the text/binary
 * pipelines.
 */
interface HasOffset
{
    public function offset(): ?int;
}
