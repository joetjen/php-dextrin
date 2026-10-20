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
 * on `Dextrin::encode()`, built on top of {@see Printer}. Every empty
 * collection and every scalar falls through to the single-line printer
 * unchanged (there's nothing multi-line about `[]` or `42`).
 *
 * **The layout** (Jan, 2026-10-20): the top-level collection always
 * breaks, one entry per line, however short it is. A nested collection
 * is written on one line, its entries single-space separated, when that
 * line -- its indent and its key included -- fits the width (`$width`,
 * 80); otherwise it breaks too, and what it holds is laid out again by
 * the same rule. Columns are counted in code points.
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
    private const DEFAULT_WIDTH = 80;

    private function __construct()
    {
    }

    public static function pretty(
        mixed $value,
        int $indentSize = self::DEFAULT_INDENT,
        ?Registry $registry = null,
        int $width = self::DEFAULT_WIDTH,
    ): string {
        return self::layout($value, 0, new Layout($indentSize, $width, $width, false, $registry));
    }

    /**
     * A nested value on one line when that fits the room its line leaves
     * it, and laid out over lines otherwise. The top level (depth 0) and a
     * value inside a one-line one go straight to `layout()`.
     */
    private static function render(mixed $value, int $depth, Layout $l): string
    {
        if ($depth === 0 || $l->flat) {
            return self::layout($value, $depth, $l);
        }
        $oneLine = self::layout($value, $depth, $l->oneLine());
        return mb_strlen($oneLine) <= $l->room ? $oneLine : self::layout($value, $depth, $l);
    }

    private static function layout(mixed $value, int $depth, Layout $l): string
    {
        if (is_object($value) && !$value instanceof DXNValueInterface && !$value instanceof \Closure && !$value instanceof \DateTimeInterface) {
            return self::renderPlainObject($value, $depth, $l);
        }
        return match (true) {
            $value instanceof DXNList && $value->items !== [] => self::bracketed('[', $value->items, ']', $depth, $l),
            $value instanceof DXNTuple && $value->items !== [] => self::bracketed('{', $value->items, '}', $depth, $l),
            $value instanceof DXNSet => $value->items === [] ? '@{}' : self::bracketed('@{', $value->items, '}', $depth, $l),
            $value instanceof DXNSortedSet => $value->items === []
                ? '@sorted-set @{}'
                : '@sorted-set ' . self::bracketed('@{', $value->items, '}', $depth, $l),
            is_array($value) && array_is_list($value) => $value === []
                ? '@array[]'
                : '@array' . self::bracketed('[', $value, ']', $depth, $l),
            $value instanceof DXNOrderedMap => $value->pairs === []
                ? '@ordered %{}'
                : '@ordered ' . self::bracketedEntries('%{', self::allowedPairs($value->pairs), '}', $depth, $l),
            $value instanceof \JOetjen\Dextrin\Value\DXNMap => count($value) === 0
                ? '%{}'
                : self::bracketedEntries('%{', self::allowedPairs($value->pairs()), '}', $depth, $l),
            // array_is_list([]) is true, so the array_is_list branch
            // above already caught the empty case -- $value here is
            // guaranteed non-empty (map-shaped) already.
            is_array($value) => self::bracketedEntries('%{', self::mapToDxnPairs($value), '}', $depth, $l),
            $value instanceof DXNStruct && $value->isKeyed() && ($value->pairs() !== [] || $value->extra !== [])
                => Printer::writtenHead($value, $l->registry)
                    . self::bracketedEntries('{', [...self::keywordPairs($value->pairs()), ...self::extraPairs($value)], '}', $depth, $l),
            // A positional struct's items, then its extra fields as
            // entries, one per line like the items (DXN.md §1.2).
            $value instanceof DXNStruct && $value->isPositional() && ($value->values() !== [] || $value->extra !== [])
                => Printer::writtenHead($value, $l->registry) . self::bracketedItemsAndEntries($value, $depth, $l),
            // Every scalar, every empty collection, and any
            // struct-shaped extended type Printer already handles
            // directly (Regex, Date, Decimal, DXNUuid, ...) needs no
            // multi-line treatment.
            default => Printer::print($value, $l->registry),
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
    private static function renderPlainObject(object $value, int $depth, Layout $l): string
    {
        $registry = $l->registry;
        $name = $registry?->fetchSchemaNameForModule($value::class);
        if ($name !== null) {
            $found = $registry->fetchStructSchema($name);
            if ($found !== null) {
                return self::render($found->compiled->structOf($name, $value), $depth, $l);
            }
        }

        if ($registry?->fetchTagEncoder($value::class) !== null) {
            return Printer::print($value, $l->registry);
        }

        $pairs = [];
        foreach (get_object_vars($value) as $key => $fieldValue) {
            $pairs[] = [new DXNKeyword($key), $fieldValue];
        }
        if ($pairs === []) {
            return Printer::print($value, $l->registry);
        }
        $shortName = substr(strrchr('\\' . $value::class, '\\') ?: '', 1);
        return "%{$shortName}" . self::bracketedEntries('{', $pairs, '}', $depth, $l);
    }

    private static function indentStr(int $depth, Layout $l): string
    {
        return str_repeat(' ', $depth * $l->indentSize);
    }

    /**
     * The rendered entries between `$open` and `$close`: single-space
     * separated on one line, or one per line, indented.
     *
     * @param list<string> $rendered
     */
    private static function joined(string $open, array $rendered, string $close, int $depth, Layout $l): string
    {
        if ($l->flat) {
            return $open . implode(' ', $rendered) . $close;
        }
        $innerIndent = self::indentStr($depth + 1, $l);
        $body = implode("\n", array_map(static fn (string $line): string => $innerIndent . $line, $rendered));
        return "{$open}\n{$body}\n" . self::indentStr($depth, $l) . $close;
    }

    /** @param list<mixed> $items */
    private static function bracketed(string $open, array $items, string $close, int $depth, Layout $l): string
    {
        $innerDepth = $depth + 1;
        $itemLayout = $l->withRoom(mb_strlen(self::indentStr($innerDepth, $l)));
        $rendered = array_map(static fn (mixed $item): string => self::render($item, $innerDepth, $itemLayout), $items);
        return self::joined($open, $rendered, $close, $depth, $l);
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

    private static function bracketedItemsAndEntries(DXNStruct $value, int $depth, Layout $l): string
    {
        $innerDepth = $depth + 1;
        $itemLayout = $l->withRoom(mb_strlen(self::indentStr($innerDepth, $l)));
        $lines = [
            ...array_map(static fn (mixed $item): string => self::render($item, $innerDepth, $itemLayout), $value->values()),
            ...array_map(
                static fn (array $pair): string => self::renderEntry($pair[0], $pair[1], $innerDepth, $l),
                self::extraPairs($value),
            ),
        ];
        return self::joined('[', $lines, ']', $depth, $l);
    }

    /** @param list<array{0: mixed, 1: mixed}> $pairs */
    private static function bracketedEntries(string $open, array $pairs, string $close, int $depth, Layout $l): string
    {
        $innerDepth = $depth + 1;
        $rendered = array_map(
            static fn (array $pair): string => self::renderEntry($pair[0], $pair[1], $innerDepth, $l),
            $pairs,
        );
        return self::joined($open, $rendered, $close, $depth, $l);
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
    private static function renderEntry(mixed $key, mixed $value, int $depth, Layout $l): string
    {
        $prefix = match (true) {
            $key instanceof DXNKeyword && Printer::isBareIdentifier($key->name) => "{$key->name}: ",
            $key instanceof DXNKeyword => ':' . Printer::quoteString($key->name) . ' => ',
            default => Printer::print($key, $l->registry) . ' => ',
        };
        $used = mb_strlen(self::indentStr($depth, $l) . $prefix);
        return $prefix . self::render($value, $depth, $l->withRoom($used));
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
