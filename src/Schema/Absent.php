<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * What {@see Validator} answers for an optional field that was not given
 * and has no default: absent, which DXN tells apart from a field written
 * `nil`. One instance, compared by class.
 */
final class Absent
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
