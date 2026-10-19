<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Value\DXNStruct;

/**
 * Which schema a decoded struct is read with, given the version it was
 * written with and the versions loaded (DXN.md §4.8). Shared by the text
 * parser and the binary decoder, so both answer every case the same way
 * -- mirrors `Dextrin.Schema.Reader`:
 *
 * - **No version, or the latest loaded:** read with the latest, exactly.
 * - **Newer than anything loaded:** read against the latest. Text matches
 *   fields by name; binary assumes positions are unchanged, drops the
 *   items beyond the reader's own and leaves missing optional fields
 *   absent. A newer version that reordered or removed fields is misread,
 *   without an error whenever the types agree -- the documented risk of
 *   reading forward.
 * - **Older and loaded:** read with that version's own schema and handed
 *   to the materializer, which upgrades it. Without one it is refused,
 *   `schema/no-materializer`: reading old data as if it were the latest
 *   would be a guess.
 * - **Older and not loaded:** refused, `schema/unknown-version`, even
 *   with a materializer, which would receive no field names.
 *
 * **What is built from the fields:** the schema's materializer, when one
 * is registered, is called for every struct of its schema, whatever the
 * version, as `$materializer($name, $version, $fields)` -- `$version`
 * being the one the fields were read with, `$fields` an array keyed by
 * field name. Its result is checked against the latest schema; a throw,
 * or a result the latest schema rejects, is `schema/materializer`.
 * Without a materializer the latest version comes back as a
 * {@see DXNStruct} at that version, its fields in the schema's order.
 * Nested structs are built first.
 */
final class Reader
{
    private function __construct()
    {
    }

    /**
     * `$struct`, as decoded, read against `$registry`. Answers the value
     * and the registry to carry on with, or `null` when the struct's name
     * has no schema at all -- it stays opaque. Throws a categorised
     * {@see DXNError}, or a {@see SchemaValidationError} the caller wraps
     * as a schema violation.
     *
     * @return array{0: mixed, 1: Registry}|null
     */
    public static function read(Registry $registry, DXNStruct $struct): ?array
    {
        $found = $registry->fetchStructSchema($struct->name);
        if ($found === null) {
            return null;
        }
        $latest = $found->compiled;
        $current = $found->registry;
        $version = $struct->version;

        if ($version === null || $version === $latest->version) {
            return self::materialized($current, $latest, $latest, $struct);
        }
        if ($version > $latest->version) {
            return self::materialized($current, $latest, $latest, self::forward($latest, $struct));
        }

        $older = $current->fetchStructSchema($struct->name, $version);
        if ($older === null) {
            throw new DXNError(
                "struct \"{$struct->name}\" was written with version {$version}, which is not loaded",
                stage: 'schema',
                category: 'schema/unknown-version',
            );
        }
        if ($current->fetchMaterializer($struct->name) === null) {
            throw new DXNError(
                "struct \"{$struct->name}\" was written with the older version {$version}, "
                . 'and no materializer is registered to upgrade it',
                stage: 'schema',
                category: 'schema/no-materializer',
            );
        }
        return self::materialized($current, $older->compiled, $latest, $struct);
    }

    /**
     * A newer writer's positional items, by the reader's own field order:
     * positions are assumed unchanged, items beyond the reader's fields
     * are dropped, and fields beyond the items are absent. A keyed struct
     * already carries its names.
     */
    private static function forward(Compiled $latest, DXNStruct $struct): DXNStruct
    {
        if ($struct->isKeyed()) {
            return $struct;
        }
        $names = $latest->fieldNames();
        $values = $struct->values();
        $pairs = [];
        $count = min(count($names), count($values));
        for ($i = 0; $i < $count; $i++) {
            $pairs[] = [$names[$i], $values[$i]];
        }
        return DXNStruct::keyed($struct->name, $pairs, $struct->version);
    }

    /**
     * The fields read with `$reading`, then built into a value: by the
     * materializer when there is one, checked against `$latest`; as a
     * generic struct at `$latest`'s version when there is none. Wrapped in
     * {@see Validated} so an outer schema's reference can tell where it
     * came from.
     *
     * @return array{0: mixed, 1: Registry}
     */
    private static function materialized(Registry $registry, Compiled $reading, Compiled $latest, DXNStruct $struct): array
    {
        [$struct, $extra] = self::separated($reading, $struct);
        if ($registry->isStrict() && $extra !== []) {
            $key = is_scalar($extra[0][0]) ? (string) $extra[0][0] : get_debug_type($extra[0][0]);
            throw new DXNError(
                "struct \"{$struct->name}\" carries \"{$key}\", which its schema does not declare",
                stage: 'schema',
                category: 'schema/undeclared-field',
            );
        }
        $pairs = Validator::resolve($reading, $struct, $registry);
        $materializer = $registry->fetchMaterializer($struct->name);
        $value = $materializer === null
            ? DXNStruct::keyed($struct->name, $pairs, $latest->version, $extra)
            : self::built($registry, $materializer, $struct->name, $reading, $latest, $pairs, $extra);
        return [new Validated($struct->name, $value), $registry];
    }

    /**
     * `$struct` with only the fields `$reading` declares, and its extra
     * fields apart (DXN.md §4.7): a keyed struct's undeclared names join
     * the extras. A name given twice -- a declared field and an extra of
     * the same name, or a keyed field written twice -- is refused: an extra
     * never shadows a declared field. A positional struct gives every
     * declared name, by position.
     *
     * @return array{0: DXNStruct, 1: list<array{0: mixed, 1: mixed}>}
     */
    private static function separated(Compiled $reading, DXNStruct $struct): array
    {
        $declared = array_flip($reading->fieldNames());
        $names = $reading->fieldNames();
        $undeclared = [];
        if ($struct->isKeyed()) {
            $mine = [];
            foreach ($struct->pairs() as [$key, $value]) {
                if (isset($declared[$key])) {
                    $mine[] = [$key, $value];
                } else {
                    $undeclared[] = [$key, $value];
                }
            }
            $names = array_map(static fn (array $pair): string => $pair[0], $mine);
            $struct = $struct->withPairs($mine);
        }
        $extra = [...$undeclared, ...$struct->extra];
        $seen = [];
        foreach ([...$names, ...array_map(static fn (array $pair): mixed => $pair[0], $extra)] as $key) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            if (isset($seen[$key])) {
                throw new DXNError(
                    "struct \"{$struct->name}\" names \"{$key}\" twice: an extra field never shadows a declared one",
                    stage: 'schema',
                    category: 'schema/shadowed-field',
                );
            }
            $seen[$key] = true;
        }
        return [$struct, $extra];
    }

    /**
     * @param \Closure(string, int, array<int|string, mixed>): mixed $materializer
     * @param list<array{0: string, 1: mixed}> $pairs
     * @param list<array{0: mixed, 1: mixed}> $extra
     */
    private static function built(
        Registry $registry,
        \Closure $materializer,
        string $name,
        Compiled $reading,
        Compiled $latest,
        array $pairs,
        array $extra,
    ): mixed {
        $fields = [];
        foreach ([...$pairs, ...$extra] as [$field, $value]) {
            // A PHP array key is a string or an integer; an extra keyed by
            // any other map key is keyed by its canonical text.
            $fields[is_string($field) || is_int($field) ? $field : \JOetjen\Dextrin\Canonical::print($field)] = $value;
        }
        try {
            $value = $materializer($name, $reading->version, $fields);
        } catch (\Throwable $err) {
            throw self::materializerError($name, "refused: {$err->getMessage()}");
        }
        try {
            Validator::validateForEncode($latest, $value, $registry);
        } catch (\Throwable $err) {
            throw self::materializerError(
                $name,
                "answered a value version {$latest->version} rejects: {$err->getMessage()}",
            );
        }
        return $value;
    }

    private static function materializerError(string $name, string $what): DXNError
    {
        return new DXNError("the materializer for \"{$name}\" {$what}", stage: 'schema', category: 'schema/materializer');
    }
}
