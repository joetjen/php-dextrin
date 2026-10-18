<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

use JOetjen\Dextrin\MapBuilder;
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
                : '@ordered ' . self::bracketedEntries('%{', self::allowedPairs($value->pairs), '}', $depth, $indentSize, $registry),
            $value instanceof \JOetjen\Dextrin\Value\DXNMap => count($value) === 0
                ? '%{}'
                : self::bracketedEntries('%{', self::allowedPairs($value->pairs()), '}', $depth, $indentSize, $registry),
            // array_is_list([]) is true, so the array_is_list branch
            // above already caught the empty case -- $value here is
            // guaranteed non-empty (map-shaped) already.
            is_array($value) => self::bracketedEntries('%{', self::mapToDxnPairs($value), '}', $depth, $indentSize, $registry),
            $value instanceof DXNStruct && $value->isKeyed() && ($value->pairs() !== [] || $value->extra !== [])
                => Printer::writtenHead($value, $registry)
                    . self::bracketedEntries('{', [...self::keywordPairs($value->pairs()), ...self::extraPairs($value)], '}', $depth, $indentSize, $registry),
            // A positional struct's items, then its extra fields as
            // entries, one per line like the items (DXN.md §1.2).
            $value instanceof DXNStruct && $value->isPositional() && ($value->values() !== [] || $value->extra !== [])
                => Printer::writtenHead($value, $registry) . self::bracketedItemsAndEntries($value, $depth, $indentSize, $registry),
            // Every scalar, every empty collection, and any
            // struct-shaped extended type Printer already handles
            // directly (Regex, Date, Decimal, DXNUuid, ...) needs no
            // multi-line treatment.
            default => Printer::print($value, $registry),
        };
    }

    /**
     * A bare PHP array's own key already tells
     * `MapBuilder::toDxnMapKey()` apart a `DXNKeyword` key from a
     * `string` key with the same text (see that class's own doc) --
     * this converts every pair to that unambiguous form before
     * `renderEntry()` ever sees it, so `renderEntry()` itself never
     * has to guess.
     *
     * @param array<int|string, mixed> $map
     * @return list<array{0: DXNKeyword|int|string, 1: mixed}>
     */
    private static function mapToDxnPairs(array $map): array
    {
        $pairs = [];
        foreach ($map as $key => $value) {
            $pairs[] = [MapBuilder::toDxnMapKey($key), $value];
        }
        return $pairs;
    }

    /**
     * Struct field names and plain-object property names are always
     * genuinely keyword-shaped (`DXN.md` §1.2's `map_entry` grammar
     * applies to `%Name{...}`'s body too) -- never ambiguous with a
     * string-typed key the way a bare `map`'s own key can be, so they
     * get wrapped as `DXNKeyword` unconditionally, up front, rather
     * than leaving `renderEntry()` to guess from a bare string alone.
     *
     * @param list<array{0: string, 1: mixed}> $pairs
     * @return list<array{0: DXNKeyword, 1: mixed}>
     */
    private static function keywordPairs(array $pairs): array
    {
        return array_map(static fn (array $p): array => [new DXNKeyword($p[0]), $p[1]], $pairs);
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
                $head = Printer::writtenHead(DXNStruct::keyed($name, []), $registry);
                $pairs = self::keywordPairs($found->compiled->fieldValues($value));
                return $pairs === [] ? "{$head}{}" : $head . self::bracketedEntries('{', $pairs, '}', $depth, $indentSize, $registry);
            }
        }

        if ($registry?->fetchTagEncoder($value::class) !== null) {
            return Printer::print($value, $registry);
        }

        $pairs = [];
        foreach (get_object_vars($value) as $key => $fieldValue) {
            $pairs[] = [new DXNKeyword($key), $fieldValue];
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

    /**
     * A struct's extra fields as entries: a name like a field name, any
     * other map key as itself.
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function extraPairs(DXNStruct $value): array
    {
        return array_map(
            static fn (array $pair): array => [is_string($pair[0]) ? new DXNKeyword($pair[0]) : $pair[0], $pair[1]],
            $value->extra,
        );
    }

    private static function bracketedItemsAndEntries(DXNStruct $value, int $depth, int $indentSize, ?Registry $registry): string
    {
        $innerDepth = $depth + 1;
        $innerIndent = self::indentStr($innerDepth, $indentSize);
        $lines = [
            ...array_map(static fn (mixed $item): string => self::render($item, $innerDepth, $indentSize, $registry), $value->values()),
            ...array_map(
                static fn (array $pair): string => self::renderEntry($pair[0], $pair[1], $innerDepth, $indentSize, $registry),
                self::extraPairs($value),
            ),
        ];
        $body = implode("\n", array_map(static fn (string $line): string => $innerIndent . $line, $lines));
        return "[\n{$body}\n" . self::indentStr($depth, $indentSize) . ']';
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

    /**
     * Same colon-shorthand-vs-arrow-form rule as {@see Printer}'s own
     * `printEntry()`/`printKeywordEntry()`: only a genuine
     * `DXNKeyword` key gets the shorthand-or-keyword-arrow treatment.
     * Every other key (`ordered-map`'s own preserved key type, or a
     * bare `map`'s already-disambiguated `MapBuilder::toDxnMapKey()`
     * result) renders through the generic value printer on the
     * arrow's left side -- correctly quoting a genuinely string-typed
     * key instead of guessing it was a keyword.
     */
    private static function renderEntry(mixed $key, mixed $value, int $depth, int $indentSize, ?Registry $registry): string
    {
        if ($key instanceof DXNKeyword) {
            if (Printer::isBareIdentifier($key->name)) {
                return "{$key->name}: " . self::render($value, $depth, $indentSize, $registry);
            }
            return ':' . Printer::quoteString($key->name) . ' => ' . self::render($value, $depth, $indentSize, $registry);
        }
        return Printer::print($key, $registry) . ' => ' . self::render($value, $depth, $indentSize, $registry);
    }

    /**
     * `$pairs`, having checked every key is one DXN.md §1.6 allows.
     *
     * @param list<array{0: mixed, 1: mixed}> $pairs
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function allowedPairs(array $pairs): array
    {
        \JOetjen\Dextrin\MapKey::checkPairs($pairs);
        return $pairs;
    }
}
