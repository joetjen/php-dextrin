<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Registry;

/**
 * The version discipline check (DXNS.md §8), mirroring
 * `Dextrin.Schema.Compat`: compares the schemas of a previous release
 * with the current ones.
 *
 *  - A schema whose content changed must have a new version: a version
 *    present in both whose fields differ -- a field added, removed,
 *    moved, or one whose type, requiredness or default changed -- is a
 *    problem, because two documents claiming to be `User` v3 must mean
 *    the same thing.
 *  - A version never goes down.
 *  - Informational: for each schema, every older version still loaded
 *    beside the latest, with the fields the latest added, removed and
 *    changed since -- a checklist for the materializer's branches.
 *
 * There are no compatibility modes and no append-only rule. A field's
 * description is documentation, not content.
 *
 * @phpstan-type Problem array{schema: string, version: int, problem: 'changed-without-new-version'}|array{schema: string, problem: 'version-went-down', from: int, to: int}
 * @phpstan-type Upgrade array{schema: string, from: int, to: int, added: list<string>, removed: list<string>, changed: list<string>}
 */
final class Compat
{
    private function __construct()
    {
    }

    /**
     * `$old`'s schemas against `$new`'s: the problems, and the upgrade
     * checklist for `$new`, each sorted by schema name and version.
     *
     * @return array{problems: list<Problem>, upgrades: list<Upgrade>}
     */
    public static function check(Registry $old, Registry $new): array
    {
        return [
            'problems' => self::problems($old->structSchemas(), $new->structSchemas()),
            'upgrades' => self::upgrades($new->structSchemas()),
        ];
    }

    /**
     * @param array<string, non-empty-array<int, Compiled>> $before
     * @param array<string, non-empty-array<int, Compiled>> $now
     * @return list<Problem>
     */
    private static function problems(array $before, array $now): array
    {
        $found = [];
        foreach (self::sorted($now) as $name => $versions) {
            if (!isset($before[$name])) {
                continue;
            }
            foreach (self::sorted($versions) as $version => $compiled) {
                $was = $before[$name][$version] ?? null;
                if ($was !== null && self::shape($was) !== self::shape($compiled)) {
                    $found[] = ['schema' => $name, 'version' => $version, 'problem' => 'changed-without-new-version'];
                }
            }
            $from = max(array_keys($before[$name]));
            $to = max(array_keys($versions));
            if ($to < $from) {
                $found[] = ['schema' => $name, 'problem' => 'version-went-down', 'from' => $from, 'to' => $to];
            }
        }
        return $found;
    }

    /**
     * @param array<string, non-empty-array<int, Compiled>> $now
     * @return list<Upgrade>
     */
    private static function upgrades(array $now): array
    {
        $found = [];
        foreach (self::sorted($now) as $name => $versions) {
            $to = max(array_keys($versions));
            foreach (self::sorted($versions) as $from => $older) {
                if ($from < $to) {
                    $found[] = self::upgrade($name, $from, $older, $to, $versions[$to]);
                }
            }
        }
        return $found;
    }

    /** @return Upgrade */
    private static function upgrade(string $name, int $from, Compiled $older, int $to, Compiled $latest): array
    {
        $before = [];
        foreach ($older->fields as $field) {
            $before[$field->name] = self::fieldShape($field);
        }
        $now = [];
        foreach ($latest->fields as $field) {
            $now[$field->name] = self::fieldShape($field);
        }
        $named = static fn (Field $field): string => $field->name;
        return [
            'schema' => $name,
            'from' => $from,
            'to' => $to,
            'added' => array_values(array_map($named, array_filter($latest->fields, static fn (Field $f) => !isset($before[$f->name])))),
            'removed' => array_values(array_map($named, array_filter($older->fields, static fn (Field $f) => !isset($now[$f->name])))),
            'changed' => array_values(array_map($named, array_filter(
                $latest->fields,
                static fn (Field $f) => isset($before[$f->name]) && $before[$f->name] !== $now[$f->name],
            ))),
        ];
    }

    /**
     * `$map` sorted by key: names (identifiers, never numeric) as
     * strings, versions as numbers -- `SORT_STRING` would put 10 before 2.
     *
     * @template K of array-key
     * @template V
     * @param array<K, V> $map
     * @return array<K, V>
     */
    private static function sorted(array $map): array
    {
        ksort($map);
        return $map;
    }

    /** What a schema's content is, for this check: its fields in order. */
    private static function shape(Compiled $compiled): string
    {
        return implode("\0", array_map(
            static fn (Field $field) => $field->name . "\0" . self::fieldShape($field),
            $compiled->fields,
        ));
    }

    /**
     * A field's requiredness, type and default, as one string compared
     * strictly -- `serialize`, because `==` on the objects a type or a
     * default holds is PHP's loose comparison, under which `1` and
     * `true` would be the same default.
     */
    private static function fieldShape(Field $field): string
    {
        return serialize([$field->required, $field->type, $field->default]);
    }
}
