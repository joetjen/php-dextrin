<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNValueInterface;

/**
 * Multi-line, indented `.dxn` rendering -- the `pretty: true` option
 * on `Dextrin::encode()`, built on top of {@see Printer}. Every
 * non-empty collection gets one entry per line, indented one level
 * deeper than its container; every empty collection and every scalar
 * falls through to the single-line printer unchanged (there's nothing
 * multi-line about `[]` or `42`).
 *
 * Comments cannot be preserved here, for the same reason no formatter
 * built on this library's decode-then-reprint approach could: the
 * lexer discards `#` comments as trivia before a value ever exists to
 * carry them forward.
 */
final class Formatter
{
    private const DEFAULT_INDENT = 2;

    private function __construct()
    {
    }

    public static function pretty(mixed $value, int $indentSize = self::DEFAULT_INDENT): string
    {
        return self::render($value, 0, $indentSize);
    }

    private static function render(mixed $value, int $depth, int $indentSize): string
    {
        return match (true) {
            $value instanceof DXNList && $value->items !== [] => self::bracketed('[', $value->items, ']', $depth, $indentSize),
            $value instanceof DXNTuple && $value->items !== [] => self::bracketed('{', $value->items, '}', $depth, $indentSize),
            $value instanceof DXNSet => $value->items === [] ? '@{}' : self::bracketed('@{', $value->items, '}', $depth, $indentSize),
            $value instanceof DXNSortedSet => $value->items === []
                ? '@sorted-set @{}'
                : '@sorted-set ' . self::bracketed('@{', $value->items, '}', $depth, $indentSize),
            is_array($value) && array_is_list($value) => $value === []
                ? '@array[]'
                : '@array' . self::bracketed('[', $value, ']', $depth, $indentSize),
            $value instanceof DXNOrderedMap => $value->pairs === []
                ? '@ordered %{}'
                : '@ordered ' . self::bracketedEntries('%{', $value->pairs, '}', $depth, $indentSize),
            // array_is_list([]) is true, so the array_is_list branch
            // above already caught the empty case -- $value here is
            // guaranteed non-empty (map-shaped) already.
            is_array($value) => self::bracketedEntries('%{', self::mapToPairs($value), '}', $depth, $indentSize),
            $value instanceof DXNStruct && $value->isKeyed() && $value->pairs() !== [] => "%{$value->name}" . self::bracketedEntries('{', $value->pairs(), '}', $depth, $indentSize),
            $value instanceof DXNStruct && $value->isPositional() && $value->values() !== [] => "%{$value->name}" . self::bracketed('[', $value->values(), ']', $depth, $indentSize),
            is_object($value) && !$value instanceof DXNValueInterface && get_object_vars($value) !== [] => self::renderPlainObject($value, $depth, $indentSize),
            // Every scalar, every empty collection, and any
            // struct-shaped extended type Printer already handles
            // directly (Regex, Date, Decimal, DXNUuid, ...) needs no
            // multi-line treatment.
            default => Printer::print($value),
        };
    }

    /**
     * @param array<int|string, mixed> $map
     * @return list<array{0: int|string, 1: mixed}>
     */
    private static function mapToPairs(array $map): array
    {
        $pairs = [];
        foreach ($map as $key => $value) {
            $pairs[] = [$key, $value];
        }
        return $pairs;
    }

    private static function renderPlainObject(object $value, int $depth, int $indentSize): string
    {
        $shortName = substr(strrchr('\\' . $value::class, '\\') ?: '', 1);
        $pairs = [];
        foreach (get_object_vars($value) as $key => $fieldValue) {
            $pairs[] = [$key, $fieldValue];
        }
        return "%{$shortName}" . self::bracketedEntries('{', $pairs, '}', $depth, $indentSize);
    }

    private static function indentStr(int $depth, int $indentSize): string
    {
        return str_repeat(' ', $depth * $indentSize);
    }

    /** @param list<mixed> $items */
    private static function bracketed(string $open, array $items, string $close, int $depth, int $indentSize): string
    {
        $innerDepth = $depth + 1;
        $innerIndent = self::indentStr($innerDepth, $indentSize);
        $outerIndent = self::indentStr($depth, $indentSize);
        $body = implode("\n", array_map(
            fn (mixed $item): string => $innerIndent . self::render($item, $innerDepth, $indentSize),
            $items,
        ));
        return "{$open}\n{$body}\n{$outerIndent}{$close}";
    }

    /** @param list<array{0: mixed, 1: mixed}> $pairs */
    private static function bracketedEntries(string $open, array $pairs, string $close, int $depth, int $indentSize): string
    {
        $innerDepth = $depth + 1;
        $innerIndent = self::indentStr($innerDepth, $indentSize);
        $outerIndent = self::indentStr($depth, $indentSize);
        $body = implode("\n", array_map(
            fn (array $pair): string => $innerIndent . self::renderEntry($pair[0], $pair[1], $innerDepth, $indentSize),
            $pairs,
        ));
        return "{$open}\n{$body}\n{$outerIndent}{$close}";
    }

    /** Same colon-shorthand-vs-arrow-form rule as {@see Printer}'s own entry printing. */
    private static function renderEntry(mixed $key, mixed $value, int $depth, int $indentSize): string
    {
        $name = match (true) {
            is_string($key) => $key,
            $key instanceof DXNKeyword => $key->name,
            default => null,
        };
        if ($name !== null) {
            if (Printer::isBareIdentifier($name)) {
                return "{$name}: " . self::render($value, $depth, $indentSize);
            }
            return ':' . Printer::quoteString($name) . ' => ' . self::render($value, $depth, $indentSize);
        }
        return Printer::print($key) . ' => ' . self::render($value, $depth, $indentSize);
    }
}
