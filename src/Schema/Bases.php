<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * The fields of a schema or shape built from others (DXNS.md §4b, Jan,
 * 2026-11-17): its bases' fields merged with its own body.
 *
 * The order is the bases', in the order the head names them, each
 * base's in its own order. A field that arrives again keeps its first
 * place, an override stays where it is, and the body's own fields come
 * last. The same field from two bases is one field when both are
 * identical -- the compiler says what identical is: their types with
 * every named type in place, so one found through this document and one
 * already loaded compare alike; two that differ are refused unless the
 * body overrides or drops that field.
 *
 * The bases themselves -- which schema or shape a name means, at which
 * version -- are found by {@see Compiler}; this class only merges what it
 * is handed.
 *
 * @phpstan-type FoundBase array{kind: 'extends'|'includes', name: string, fields: list<Field>}
 */
final class Bases
{
    private function __construct()
    {
    }

    /**
     * `$own` and `$drops` merged onto `$bases`' fields.
     *
     * A base whose fields hold `a` and an optional `b`, merged with an own
     * field `c` and the drop `b`, gives `a`, `c`.
     *
     * @param list<FoundBase> $bases
     * @param list<Field> $own
     * @param list<string> $drops
     * @param (\Closure(Field, Field): bool)|null $same whether two fields of one
     *   name from two bases are one field; equal as values when not given
     * @return list<Field>
     * @throws SchemaCompileError naming, in words, what does not merge.
     */
    public static function merge(array $bases, array $own, array $drops, ?\Closure $same = null): array
    {
        $same ??= static fn (Field $a, Field $b): bool => $a == $b;
        $ownNames = array_map(static fn (Field $f): string => $f->name, $own);
        self::distinctDrops($drops);
        $inherited = self::inherited($bases, [...$ownNames, ...$drops], $same);
        self::dropsInherited($drops, $inherited, $ownNames);
        self::dropsOptional($drops, $bases);

        $byName = [];
        foreach ($own as $field) {
            $byName[$field->name] = $field;
        }
        $merged = [];
        $placed = [];
        foreach ($inherited as $field) {
            if (in_array($field->name, $drops, true)) {
                continue;
            }
            $merged[] = $byName[$field->name] ?? $field;
            $placed[$field->name] = true;
        }
        foreach ($own as $field) {
            if (!isset($placed[$field->name])) {
                $merged[] = $field;
            }
        }
        return $merged;
    }

    /**
     * Every base's fields in turn, each name once, its first place kept.
     * Two different fields of one name are refused unless `$settled` --
     * the names the body overrides or drops -- holds it. `$same` tells
     * whether two are one.
     *
     * @param list<FoundBase> $bases
     * @param list<string> $settled
     * @param \Closure(Field, Field): bool $same
     * @return list<Field>
     */
    private static function inherited(array $bases, array $settled, \Closure $same): array
    {
        $fields = [];
        $seen = [];
        foreach ($bases as $base) {
            foreach ($base['fields'] as $field) {
                $first = $seen[$field->name] ?? null;
                if ($first === null) {
                    $fields[] = $field;
                    $seen[$field->name] = $field;
                } elseif (!$same($first, $field) && !in_array($field->name, $settled, true)) {
                    throw new SchemaCompileError(
                        "field \"{$field->name}\" differs between its bases; override it, or drop it",
                    );
                }
            }
        }
        return $fields;
    }

    /** @param list<string> $drops */
    private static function distinctDrops(array $drops): void
    {
        foreach (array_count_values($drops) as $name => $count) {
            if ($count > 1) {
                throw new SchemaCompileError("field \"{$name}\" is dropped twice");
            }
        }
    }

    /**
     * Every drop names a field some base has, and none is declared as well.
     *
     * @param list<string> $drops
     * @param list<Field> $inherited
     * @param list<string> $ownNames
     */
    private static function dropsInherited(array $drops, array $inherited, array $ownNames): void
    {
        $names = array_map(static fn (Field $f): string => $f->name, $inherited);
        foreach ($drops as $drop) {
            if (!in_array($drop, $names, true)) {
                throw new SchemaCompileError("- {$drop} drops a field no base has");
            }
        }
        foreach ($drops as $drop) {
            if (in_array($drop, $ownNames, true)) {
                throw new SchemaCompileError("field \"{$drop}\" is both dropped and declared");
            }
        }
    }

    /**
     * No value without a required field of an `extends` base could pass as
     * that base; `includes` is for leaving one out.
     *
     * @param list<string> $drops
     * @param list<FoundBase> $bases
     */
    private static function dropsOptional(array $drops, array $bases): void
    {
        foreach ($bases as $base) {
            if ($base['kind'] !== 'extends') {
                continue;
            }
            foreach ($base['fields'] as $field) {
                if ($field->required && in_array($field->name, $drops, true)) {
                    throw new SchemaCompileError(
                        "- {$field->name} drops a required field of {$base['name']}, which this schema extends; "
                        . "include {$base['name']} instead",
                    );
                }
            }
        }
    }
}
