<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

/**
 * Shared escape decoding/encoding for DXN's `string`/`char`/quoted
 * -`keyword` bodies (`dxn/DXN.md` §1.1's `escape` production) -- one
 * implementation so no caller duplicates it.
 *
 * Decode accepts more named shortcuts (`\0 \a \b \f \v`, alongside
 * `\" \\ \n \t \r` and `\x{...}`) than encode ever produces -- encode
 * only ever emits `\" \\ \n \t \r` as named escapes, falling back to
 * `\x{...}` for every other C0 control character, mirroring the JS
 * port's own asymmetric choice: a decoder must accept every input
 * form; a printer need not exercise all of them to stay round-trip
 * -safe.
 */
final class Escapes
{
    private const DECODE_SIMPLE = [
        '"' => '"', '\\' => '\\', 'n' => "\n", 't' => "\t", 'r' => "\r",
        '0' => "\x00", 'a' => "\x07", 'b' => "\x08", 'f' => "\x0c", 'v' => "\x0b",
    ];

    private const ENCODE_NAMED = ['"' => '"', '\\' => '\\', "\n" => 'n', "\t" => 't', "\r" => 'r'];

    private function __construct()
    {
    }

    /**
     * Decodes a full string/keyword body -- the text between the
     * opening and closing `"`, quotes already stripped.
     * @throws \InvalidArgumentException on an unrecognized or malformed escape.
     */
    public static function decodeString(string $body): string
    {
        $out = '';
        $i = 0;
        $len = strlen($body);
        while ($i < $len) {
            $ch = $body[$i];
            if ($ch === '\\') {
                [$text, $next] = self::decodeEscape($body, $i + 1);
                $out .= $text;
                $i = $next;
            } else {
                $out .= $ch;
                $i++;
            }
        }
        return $out;
    }

    /**
     * Decodes exactly one escape sequence starting right after the
     * leading backslash (`$body[$start]` is the escape's first byte,
     * not the backslash itself).
     * @return array{0: string, 1: int} the decoded text and the byte
     * offset right after the consumed escape.
     */
    public static function decodeEscape(string $body, int $start): array
    {
        if ($start >= strlen($body)) {
            throw new \InvalidArgumentException('dangling backslash at end of input');
        }
        $c = $body[$start];

        if ($c === 'x') {
            if (($body[$start + 1] ?? '') !== '{') {
                throw new \InvalidArgumentException("invalid \\x{...} escape: expected '{'");
            }
            $j = $start + 2;
            $hex = '';
            while ($j < strlen($body) && ctype_xdigit($body[$j])) {
                $hex .= $body[$j];
                $j++;
            }
            if ($hex === '' || ($body[$j] ?? '') !== '}') {
                throw new \InvalidArgumentException('invalid \\x{...} escape');
            }
            $codepoint = (int) hexdec($hex);
            if ($codepoint > 0x10FFFF) {
                throw new \InvalidArgumentException("invalid \\x{...} escape: codepoint out of range");
            }
            return [mb_chr($codepoint, 'UTF-8'), $j + 1];
        }

        $simple = self::DECODE_SIMPLE[$c] ?? null;
        if ($simple === null) {
            throw new \InvalidArgumentException("unrecognized escape sequence '\\{$c}'");
        }
        return [$simple, $start + 1];
    }

    /**
     * Encodes a string body for `.dxn` text output -- the reverse of
     * {@see self::decodeString()}. Escapes only what round-tripping
     * requires (`"`, `\`, and the C0 control-character range), leaving
     * every other codepoint, including non-ASCII text, untouched.
     */
    public static function encodeString(string $s): string
    {
        $out = '';
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $out .= self::encodeChar(mb_ord(mb_substr($s, $i, 1, 'UTF-8'), 'UTF-8'));
        }
        return $out;
    }

    public static function encodeChar(int $codepoint): string
    {
        $ch = mb_chr($codepoint, 'UTF-8');
        $named = self::ENCODE_NAMED[$ch] ?? null;
        if ($named !== null) {
            return "\\{$named}";
        }
        if ($codepoint < 0x20) {
            return sprintf('\\x{%x}', $codepoint);
        }
        return $ch;
    }
}
