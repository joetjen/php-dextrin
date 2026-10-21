<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Support;

use JOetjen\Dextrin\Value\DXNStruct;

/**
 * A decoded value with every keyed {@see DXNStruct} in it turned into an
 * array of its fields, keyed by name -- for tests about what a schema
 * accepts, which care about the checked fields and not about the generic
 * struct a schema with no materializer decodes to (DXNS.md §8).
 */
final class DecodedFields
{
    private function __construct()
    {
    }

    public static function of(mixed $value): mixed
    {
        if ($value instanceof DXNStruct && $value->isKeyed()) {
            $fields = [];
            foreach ($value->pairs() as [$name, $field]) {
                $fields[$name] = self::of($field);
            }
            return $fields;
        }
        if (is_array($value)) {
            return array_map(self::of(...), $value);
        }
        return $value;
    }
}
