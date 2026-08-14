<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

use JOetjen\Dextrin\Registry;
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
 *
 * Same optional `$registry` as {@see Printer}, threaded through so a
 * registered application class instance prints under its schema's
 * name -- see {@see Printer}'s own doc.
 */
final class Formatter
{
    private const DEFAULT_INDENT = 2;

    private function __construct()
    {
    }

    public static function pretty(mixed $value, int $indentSize = self::DEFAULT_INDENT, ?Registry $registry = null): string
    {
        return self::render($value, 0, $indentSize, $registry);
    }

    private static function render(mixed $value, int $depth, int $indentSize, ?Registry $registry): string
    {
        if (is_object($value) && !$value instanceof DXNValueInterface && !$value instanceof \Closure) {
            return self::renderPlainObject($value, $depth, $indentSize, $registry);
        }
        return match (true) {
            $value instanceof DXNList && $value->items !== [] => self::bracketed('[', $value->items, ']', $depth, $indentSize, $registry),
            $value instanceof DXNTuple && $value->items !== [] => self::bracketed('{', $value->items, '}', $depth, $indentSize, $registry),
            $value instanceof DXNSet => $value->items === [] ? '@{}' : self::bracketed('@{', $value->items, '}', $depth, $indentSize, $registry),
            $value instanceof DXNSortedSet => $value->items === []
                ? '@sorted-set @{}'
                : '@sorted-set ' . self::bracketed('@{', $value->items, '}', $depth, $indentSize, $registry),
            is_array($value) && array_is_list($value) => $value === []
                ? '@array[]'
                : '@array' . self::bracketed('[', $value, ']', $depth, $indentSize, $registry),
            $value instanceof DXNOrderedMap => $value->pairs === []
                ? '@ordered %{}'
                : '@ordered ' . self::bracketedEntries('%{', $value->pairs, '}', $depth, $indentSize, $registry),
            // array_is_list([]) is true, so the array_is_list branch
            // above already caught the empty case -- $value here is
            // guaranteed non-empty (map-shaped) already.
            is_array($value) => self::bracketedEntries('%{', self::mapToPairs($value), '}', $depth, $indentSize, $registry),
            $value instanceof DXNStruct && $value->isKeyed() && $value->pairs() !== [] => "%{$value->name}" . self::bracketedEntries('{', $value->pairs(), '}', $depth, $indentSize, $registry),
            $value instanceof DXNStruct && $value->isPositional() && $value->values() !== [] => "%{$value->name}" . self::bracketed('[', $value->values(), ']', $depth, $indentSize, $registry),
            // Every scalar, every empty collection, and any
            // struct-shaped extended type Printer already handles
            // directly (Regex, Date, Decimal, DXNUuid, ...) needs no
            // multi-line treatment.
            default => Printer::print($value, $registry),
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

    /**
     * A genuine application class instance -- every built-in DXN
     * shape is already handled by `render()`'s own dispatch above.
     * Same three-way registry precedence as {@see
     * Printer::printPlainObjectAsStruct()}'s own doc, in one pass (no
     * repeated registry lookups, unlike calling that precedence twice
     * from two different helpers would need):
     *
     *   1. A struct schema registered for this class renders as that
     *      named struct, multi-line if it has any fields.
     *   2. Otherwise, a registered tag encoder means this isn't
     *      struct-shaped at all -- defers entirely to
     *      `Printer::print()`'s own single-line `@tag value` handling,
     *      the same precedent already set for `DXNCustomTag` (never
     *      rendered multi-line by this class either).
     *   3. Otherwise, the PHP-specific "any object is data"
     *      convenience: multi-line if it has any public properties,
     *      single-line (`Printer::print()`) if it has none.
     */
    private static function renderPlainObject(object $value, int $depth, int $indentSize, ?Registry $registry): string
    {
        $name = $registry?->fetchSchemaNameForModule($value::class);
        if ($name !== null) {
            $found = $registry->fetchStructSchema($name);
            if ($found !== null) {
                $pairs = $found->compiled->fieldValues($value);
                return $pairs === [] ? "%{$name}{}" : "%{$name}" . self::bracketedEntries('{', $pairs, '}', $depth, $indentSize, $registry);
            }
        }

        if ($registry?->fetchTagEncoder($value::class) !== null) {
            return Printer::print($value, $registry);
        }

        $pairs = [];
        foreach (get_object_vars($value) as $key => $fieldValue) {
            $pairs[] = [$key, $fieldValue];
        }
        if ($pairs === []) {
            return Printer::print($value, $registry);
        }
        $shortName = substr(strrchr('\\' . $value::class, '\\') ?: '', 1);
        return "%{$shortName}" . self::bracketedEntries('{', $pairs, '}', $depth, $indentSize, $registry);
    }

    private static function indentStr(int $depth, int $indentSize): string
    {
        return str_repeat(' ', $depth * $indentSize);
    }

    /** @param list<mixed> $items */
    private static function bracketed(string $open, array $items, string $close, int $depth, int $indentSize, ?Registry $registry): string
    {
        $innerDepth = $depth + 1;
        $innerIndent = self::indentStr($innerDepth, $indentSize);
        $outerIndent = self::indentStr($depth, $indentSize);
        $body = implode("\n", array_map(
            static fn (mixed $item): string => $innerIndent . self::render($item, $innerDepth, $indentSize, $registry),
            $items,
        ));
        return "{$open}\n{$body}\n{$outerIndent}{$close}";
    }

    /** @param list<array{0: mixed, 1: mixed}> $pairs */
    private static function bracketedEntries(string $open, array $pairs, string $close, int $depth, int $indentSize, ?Registry $registry): string
    {
        $innerDepth = $depth + 1;
        $innerIndent = self::indentStr($innerDepth, $indentSize);
        $outerIndent = self::indentStr($depth, $indentSize);
        $body = implode("\n", array_map(
            static fn (array $pair): string => $innerIndent . self::renderEntry($pair[0], $pair[1], $innerDepth, $indentSize, $registry),
            $pairs,
        ));
        return "{$open}\n{$body}\n{$outerIndent}{$close}";
    }

    /** Same colon-shorthand-vs-arrow-form rule as {@see Printer}'s own entry printing. */
    private static function renderEntry(mixed $key, mixed $value, int $depth, int $indentSize, ?Registry $registry): string
    {
        $name = match (true) {
            is_string($key) => $key,
            $key instanceof DXNKeyword => $key->name,
            default => null,
        };
        if ($name !== null) {
            if (Printer::isBareIdentifier($name)) {
                return "{$name}: " . self::render($value, $depth, $indentSize, $registry);
            }
            return ':' . Printer::quoteString($name) . ' => ' . self::render($value, $depth, $indentSize, $registry);
        }
        return Printer::print($key, $registry) . ' => ' . self::render($value, $depth, $indentSize, $registry);
    }
}
