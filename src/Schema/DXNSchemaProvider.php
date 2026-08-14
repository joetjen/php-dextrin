<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * Lets a class's *own* library ship a DXN schema for it -- field
 * names, types, required/optional, closed/forbidden, refinements --
 * without that library ever taking a runtime dependency on this one.
 * The `struct` (schema-backed) counterpart to what
 * {@see \JOetjen\Dextrin\Registry::putTagEncoder()} already does for
 * simple scalar-wrapping `custom-tag` values: an extension point owned
 * by the *consuming* application, not forced onto every class-defining
 * library it might want to use with `dextrin`.
 *
 * Implement {@see DXNSchemaMaterializingProvider} instead of this
 * interface directly when the schema's decoded field map should
 * become something nicer than a bare field array -- an interface
 * split, rather than an optional method the way the JS port's own
 * duck-typed `dxnMaterialize?` is, since PHP interfaces can't declare
 * an optional method.
 */
interface DXNSchemaProvider
{
    /** This provider's own `.dxns` source -- every named type and every `%schema{}` entry it defines gets compiled in, not only {@see self::dxnSchemaName()}'s own entry. */
    public function dxnSchema(): string;

    /** Which `%schema{}` entry in {@see self::dxnSchema()}'s own document this provider is for. */
    public function dxnSchemaName(): string;

    /**
     * The PHP class {@see self::dxnSchemaName()}'s schema is
     * associated with, for `{form: 'reference', name}` checks during
     * encode-time validation.
     *
     * @return class-string
     */
    public function dxnClass(): string;
}
