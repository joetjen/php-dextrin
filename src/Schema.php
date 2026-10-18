<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

use JOetjen\Dextrin\Schema\Compiler;
use JOetjen\Dextrin\Schema\DXNSchemaProvider;
use JOetjen\Dextrin\Schema\Provider;
use JOetjen\Dextrin\Schema\SchemaValidationError;
use JOetjen\Dextrin\Schema\Validated;
use JOetjen\Dextrin\Schema\Reader;
use JOetjen\Dextrin\Schema\Validator;
use JOetjen\Dextrin\Value\DXNStruct;

/**
 * Public entry point for `.dxns` schema compilation and validation.
 *
 *   - {@see self::compile()} -- the one-time walk from a decoded
 *     `.dxns` document to a populated {@see Registry}.
 *   - {@see self::validate()} -- checks an already-decoded value
 *     against a named schema (decode-side, but not decode itself --
 *     see its own doc).
 *   - {@see self::validateEncode()}/{@see self::validateEncodeTree()}
 *     -- the encode-side mirror, one named schema vs. an automatic
 *     whole-tree walk. Unlike the Elixir original, both may
 *     *transform* the value they check (see
 *     {@see \JOetjen\Dextrin\Schema\Validator}'s own doc on
 *     schema-driven coercion).
 *
 * Enforcement during actual decoding is fail-fast and automatic: every
 * registered struct name is checked unconditionally as part of
 * {@see Dextrin::decode()}/{@see Dextrin::decodeBinary()}. The methods
 * here are for the cases that aren't "check while decoding" (see
 * {@see self::validate()}'s own doc).
 */
final class Schema
{
    private function __construct()
    {
    }

    /**
     * Compiles a decoded `.dxns` document into `$baseRegistry` -- a
     * struct schema for each `%schema(N){}` entry, and a reusable named
     * type for every other entry.
     */
    public static function compile(mixed $schemaDoc, Registry $baseRegistry): Registry
    {
        return Compiler::compile($schemaDoc, $baseRegistry);
    }

    /**
     * Validates an already-decoded value against a named, compiled
     * schema -- for the cases that aren't "check while decoding": a
     * value built directly in PHP before encoding, an opaque
     * `DXNStruct` decoded before a schema became available, or
     * checking against a different schema than the one originally
     * used. Returns `true` on success, throws {@see
     * SchemaValidationError} on failure.
     */
    public static function validate(mixed $value, Registry $registry, string $schemaName): true
    {
        if (!$value instanceof DXNStruct || $value->name !== $schemaName) {
            throw new SchemaValidationError("expected a DXNStruct named \"{$schemaName}\", got " . get_debug_type($value));
        }
        // Read exactly as a decode would read it, version and
        // materializer included ({@see Reader}).
        if (Reader::read($registry, $value) === null) {
            throw new SchemaValidationError("no compiled schema registered for \"{$schemaName}\"");
        }
        return true;
    }

    /**
     * Encode-time schema validation: checks `$value` -- ordinary PHP
     * data you're about to encode -- against a named, compiled schema
     * *before* encoding produces any output. The mirror image of
     * {@see self::validate()}, for data on its way out rather than in.
     * Returns the checked value, stripped of internal bookkeeping;
     * nothing is converted (DXN.md §4.7).
     */
    public static function validateEncode(mixed $value, Registry $registry, string $schemaName): mixed
    {
        $found = $registry->fetchStructSchema($schemaName);
        if ($found === null) {
            throw new SchemaValidationError("no compiled schema registered for \"{$schemaName}\"");
        }
        return Validated::strip(Validator::validateForEncode($found->compiled, $value, $registry));
    }

    /**
     * Automatic, name-driven encode-time validation: walks `$value`
     * and checks *every* `DXNStruct` or registered application class
     * instance anywhere in it against its own schema, wherever it
     * turns out to be. Symmetric to how decode checks every named
     * struct unconditionally. `Dextrin::encode()`/`encodeBinary()` call
     * this automatically by default. Returns the checked tree.
     */
    public static function validateEncodeTree(mixed $value, Registry $registry): mixed
    {
        return Validated::strip(Validator::validateTreeForEncode($value, $registry));
    }

    /**
     * Compiles and registers one schema provider
     * ({@see \JOetjen\Dextrin\Schema\Provider}) into `$registry` --
     * lets a class's own library ship its DXN schema without that
     * library depending on this one.
     */
    public static function registerProvider(Registry $registry, DXNSchemaProvider $provider): Registry
    {
        return \JOetjen\Dextrin\Schema\Provider::registerProvider($registry, $provider, self::compile(...));
    }
}
