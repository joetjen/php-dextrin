<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNDuration;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTime;
use JOetjen\Dextrin\Value\DXNTimestamp;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUri;
use JOetjen\Dextrin\Value\DXNUuid;
use JOetjen\Dextrin\Value\DXNValueInterface;

/**
 * `.dxn` printer -- the reverse of {@see Parser}. Single-line,
 * minimal-whitespace output: a printer, not a formatter (no
 * line-wrapping/indentation policy is specified anywhere in
 * `dxn/DXN.md`) -- {@see Formatter} owns multi-line, human-readable
 * rendering, built on top of this class.
 *
 * Throws on the one thing that can actually fail here: a value with
 * no DXN representation at all (a resource, a closure, ...) -- every
 * built-in DXN-representable shape, including any plain object with
 * public properties (auto-encoded as `%ClassName{...}`, this port's
 * struct-encoding convenience), always succeeds.
 */
final class Printer
{
    private const REGEX_FLAG_ORDER = ['i', 'm', 's', 'u', 'x', 'f', 'r'];

    private function __construct()
    {
    }

    public static function print(mixed $value): string
    {
        return match (true) {
            $value === null => 'nil',
            $value === true => 'true',
            $value === false => 'false',
            $value instanceof DXNInteger => $value->toString(),
            is_int($value) => (string) $value,
            is_float($value) => self::printFloat($value),
            is_string($value) => self::quoteString($value),
            $value instanceof DXNDecimal => $value->toString() . 'M',
            $value instanceof DXNRational => "{$value->numerator}/{$value->denominator}",
            $value instanceof DXNChar => '?' . self::printCharBody($value->codepoint),
            $value instanceof DXNSymbol => $value->name,
            $value instanceof DXNKeyword => ':' . self::keywordName($value->name),
            $value instanceof DXNBytes => self::tagForm('bytes', self::quoteString(base64_encode($value->data))),
            $value instanceof DXNList => '[' . implode(' ', array_map(self::print(...), $value->items)) . ']',
            $value instanceof DXNTuple => '{' . implode(' ', array_map(self::print(...), $value->items)) . '}',
            $value instanceof DXNSet => '@{' . implode(' ', array_map(self::print(...), $value->items)) . '}',
            $value instanceof DXNSortedSet => self::tagForm('sorted-set', '@{' . implode(' ', array_map(self::print(...), $value->items)) . '}'),
            $value instanceof DXNOrderedMap => self::tagForm('ordered', '%{' . self::printOrderedEntries($value->pairs) . '}'),
            $value instanceof DXNStruct => self::printStruct($value),
            $value instanceof DXNDate => '~D[' . Temporal::formatDate($value) . ']',
            $value instanceof DXNTime => '~T[' . Temporal::formatTime($value) . ']',
            $value instanceof DXNTimestamp => '~U[' . Temporal::formatInstant($value) . ']',
            $value instanceof DXNDateTime => self::tagForm('datetime', self::quoteString(Temporal::formatOffsetDateTime($value))),
            $value instanceof DXNDuration => self::tagForm('duration', self::quoteString(Temporal::formatDuration($value))),
            $value instanceof DXNUuid => self::tagForm('uuid', self::quoteString($value->toString())),
            $value instanceof DXNUri => self::tagForm('uri', self::quoteString($value->value)),
            $value instanceof DXNRegex => '~r/' . $value->pattern . '/' . self::printRegexFlags($value->flags),
            $value instanceof DXNCustomTag => self::tagForm($value->name, self::print($value->value)),
            is_array($value) && array_is_list($value) => self::tagForm('array', '[' . implode(' ', array_map(self::print(...), $value)) . ']'),
            is_array($value) => '%{' . self::printMapEntries($value) . '}',
            // A Closure is categorically code, not data -- letting it
            // fall through to the plain-object convenience would
            // silently print it as an empty struct (it has no public
            // properties) instead of failing the way it should for
            // "no DXN representation."
            is_object($value) && !$value instanceof DXNValueInterface && !$value instanceof \Closure => self::printPlainObjectAsStruct($value),
            default => throw new \InvalidArgumentException('cannot encode value with no DXN representation: ' . get_debug_type($value)),
        };
    }

    private static function printFloat(float $n): string
    {
        if (is_nan($n)) {
            return 'NaN';
        }
        if ($n === INF) {
            return 'Infinity';
        }
        if ($n === -INF) {
            return '-Infinity';
        }
        $s = (string) $n;
        // PHP omits the decimal point for integer-valued floats
        // ((string) 1.0 === "1"), which .dxn needs to stay distinct
        // from `integer` -- append ".0" whenever the native rendering
        // has neither a fraction nor an exponent to make that
        // distinction implicit already.
        return preg_match('/[.eE]/', $s) === 1 ? $s : "{$s}.0";
    }

    private static function printCharBody(int $codepoint): string
    {
        return match ($codepoint) {
            0x20 => 's',
            0x0a => '\\n',
            0x09 => '\\t',
            0x0d => '\\r',
            0x22 => '\\"',
            0x5c => '\\\\',
            default => $codepoint < 0x20 ? sprintf('\\x{%x}', $codepoint) : mb_chr($codepoint, 'UTF-8'),
        };
    }

    public static function quoteString(string $s): string
    {
        return '"' . Escapes::encodeString($s) . '"';
    }

    private static function tagForm(string $name, string $printedValue): string
    {
        return "@{$name} {$printedValue}";
    }

    /** @param array<string, bool> $flags */
    private static function printRegexFlags(array $flags): string
    {
        $out = '';
        foreach (self::REGEX_FLAG_ORDER as $f) {
            if ($flags[$f]) {
                $out .= $f;
            }
        }
        return $out;
    }

    /**
     * Whether `$name` is a valid bare `identifier` on its own -- the
     * one thing that decides whether a keyword/field name can use the
     * colon-shorthand at all, or must fall back to the quoted arrow
     * form. Reuses the real tokenizer rather than a hand-rolled
     * regex -- one source of truth for what an identifier is.
     */
    public static function isBareIdentifier(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        try {
            $tokens = Lexer::tokenize($name);
        } catch (LexError) {
            return false;
        }
        return count($tokens) === 2 && $tokens[0]->type === 'IDENTIFIER' && $tokens[0]->value === $name;
    }

    private static function keywordName(string $name): string
    {
        return self::isBareIdentifier($name) ? $name : self::quoteString($name);
    }

    /** @param list<array{0: mixed, 1: mixed}> $pairs */
    private static function printOrderedEntries(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as [$key, $value]) {
            $parts[] = self::printEntry($key, $value);
        }
        return implode(',', $parts);
    }

    private static function printEntry(mixed $key, mixed $value): string
    {
        if ($key instanceof DXNKeyword) {
            return self::printKeywordEntry($key->name, $value);
        }
        return self::print($key) . '=>' . self::print($value);
    }

    /**
     * A `map` decodes to a bare PHP array, which has already
     * collapsed a `DXNKeyword` key and a `string` key with the same
     * text into one PHP string key (this port's own documented
     * limit) -- so encoding always produces the keyword form for
     * every string key, consistently, rather than trying to guess
     * which one the caller "meant."
     *
     * @param array<int|string, mixed> $map
     */
    private static function printMapEntries(array $map): string
    {
        $parts = [];
        foreach ($map as $key => $value) {
            $parts[] = is_string($key) ? self::printKeywordEntry($key, $value) : "{$key}=>" . self::print($value);
        }
        return implode(',', $parts);
    }

    private static function printKeywordEntry(string $name, mixed $value): string
    {
        if (self::isBareIdentifier($name)) {
            return "{$name}:" . self::print($value);
        }
        return ':' . self::quoteString($name) . '=>' . self::print($value);
    }

    private static function printStruct(DXNStruct $struct): string
    {
        if ($struct->isKeyed()) {
            $parts = [];
            foreach ($struct->pairs() as [$name, $value]) {
                $parts[] = self::printKeywordEntry($name, $value);
            }
            return "%{$struct->name}{" . implode(',', $parts) . '}';
        }
        $items = implode(',', array_map(self::print(...), $struct->values()));
        return "%{$struct->name}[{$items}]";
    }

    /**
     * Any plain PHP object with public properties (`stdClass` or
     * otherwise) auto-encodes as a keyed struct named after its own
     * class, via `get_object_vars()` -- the project's own
     * struct-encoding convenience, a hand-build-a-`DXNStruct`-free way
     * to encode ad-hoc structured data.
     */
    private static function printPlainObjectAsStruct(object $value): string
    {
        $shortName = substr(strrchr('\\' . $value::class, '\\') ?: '', 1);
        $parts = [];
        foreach (get_object_vars($value) as $name => $fieldValue) {
            $parts[] = self::printKeywordEntry($name, $fieldValue);
        }
        return "%{$shortName}{" . implode(',', $parts) . '}';
    }
}
