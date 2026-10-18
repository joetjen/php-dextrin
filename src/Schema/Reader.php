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
            return self::materialized($current, $latest, $struct);
        }
        if ($version > $latest->version) {
            return self::materialized($current, $latest, self::forward($latest, $struct));
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
        return self::materialized($current, $older->compiled, $struct);
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

    /** @return array{0: mixed, 1: Registry} */
    private static function materialized(Registry $registry, Compiled $compiled, DXNStruct $struct): array
    {
        $materializer = $registry->fetchMaterializer($struct->name);
        return [Validator::materialize($compiled, $struct, $materializer, $registry), $registry];
    }
}
