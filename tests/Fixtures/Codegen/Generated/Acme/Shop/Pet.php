<?php

// dextrin:schema acme.shop/Pet

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Fixtures\Codegen\Generated\Acme\Shop;

/**
 * The schema acme.shop/Pet. Its properties and methods come from
 * {@see AbstractPet}, which `bin/dextrin gen-php` rewrites;
 * this file is yours and is never rewritten.
 */
class Pet extends AbstractPet
{
    /**
     * The one hand-written part of this fixture, as a user writes it:
     * `born` was an integer in version 1 and is a string in version 2.
     * Everything else under Generated/ is written by `bin/dextrin gen-php`
     * from ../schemas (see tests/Codegen/PhpTest.php); regenerate it,
     * never edit it.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected static function upgradeV1(array $fields): array
    {
        $born = $fields['born'];
        $fields['born'] = is_int($born) ? (string) $born : $born;
        return $fields;
    }
}
