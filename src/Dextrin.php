<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

use JOetjen\Dextrin\Text\Formatter;
use JOetjen\Dextrin\Text\Parser;
use JOetjen\Dextrin\Text\Printer;

/**
 * Public API entry point: `.dxn` text decode/encode. `.dxnb` binary
 * and `.dxns` schema support land in later phases.
 */
final class Dextrin
{
    private function __construct()
    {
    }

    /**
     * Decodes `.dxn` text into a value -- every DXN type maps to a
     * native PHP value or a `DXN*` wrapper class, see the project's
     * value-model mapping (`README.md`) for the full picture. Throws
     * {@see DXNError} on malformed input.
     */
    public static function decode(string $text): mixed
    {
        try {
            return Parser::parse($text);
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'parse');
        }
    }

    /**
     * Encodes a value back to `.dxn` text. Single-line,
     * minimal-whitespace by default; pass `pretty: true` for
     * multi-line, indented output instead (`indent` then sets spaces
     * per nesting level, default 2). Both produce the exact same
     * value on the way back through `decode()` -- `pretty`/`indent`
     * are a rendering choice, never a semantic one. Throws
     * {@see DXNError} for a value with no DXN representation.
     */
    public static function encode(mixed $value, bool $pretty = false, int $indent = 2): string
    {
        try {
            return $pretty ? Formatter::pretty($value, $indent) : Printer::print($value);
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'encode');
        }
    }
}
