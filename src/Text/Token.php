<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

/**
 * One lexed token. `start`/`end` are **codepoint indices**, not byte
 * offsets -- {@see Lexer} operates on the source split into an array
 * of single-codepoint strings (`preg_split('//u', ...)`) rather than
 * raw byte-indexed PHP strings, which sidesteps multi-byte UTF-8
 * sequences entirely (a JS port indexes UTF-16 code units natively;
 * PHP strings are raw bytes, so this port picks codepoints as its own
 * natural, consistently-defined unit instead).
 */
final class Token
{
    public function __construct(
        public readonly string $type,
        public readonly mixed $value,
        public readonly int $start,
        public readonly int $end,
        public readonly bool $isQuoted = false,
    ) {
    }
}
