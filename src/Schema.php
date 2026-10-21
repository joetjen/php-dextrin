<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

use JOetjen\Dextrin\Schema\Compat;
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
 *   - {@see self::annotations()} -- what a `{:doc}` around a schema or
 *     named type says about it (DXNS.md §1b).
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
     * type for every other entry. `$names`, when given, maps each entry's
     * key to the full name a tree gives it (DXNS.md §6) -- how {@see
     * Schema\Tree} compiles a file -- a bare symbol naming a sibling
     * resolving to it.
     *
     * @param array<string, string> $names
     */
    public static function compile(mixed $schemaDoc, Registry $baseRegistry, array $names = []): Registry
    {
        return Compiler::compile($schemaDoc, $baseRegistry, $names);
    }

    /**
     * Compiles several decoded `.dxns` documents into one registry, in
     * order, over `$baseRegistry` (a new one by default) -- each seeing the
     * schemas and named types of those before it, as {@see compile()}
     * called on each in turn. The first that does not compile throws,
     * naming which it was (`schema/compile`). As the reference's
     * `Dextrin.Schema.compile_all/2`.
     *
     * @param list<mixed> $schemaDocs
     */
    public static function compileAll(array $schemaDocs, ?Registry $baseRegistry = null): Registry
    {
        $registry = $baseRegistry ?? new Registry();
        foreach ($schemaDocs as $index => $doc) {
            try {
                $registry = Compiler::compile($doc, $registry);
            } catch (Schema\SchemaCompileError $err) {
                throw new Schema\SchemaCompileError('document ' . ($index + 1) . ': ' . $err->getMessage());
            }
        }
        return $registry;
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
            throw new SchemaValidationError(
                "expected a DXNStruct named \"{$schemaName}\", got " . get_debug_type($value),
                'schema/type-mismatch',
            );
        }
        // Read exactly as a decode would read it, version and
        // materializer included ({@see Reader}).
        if (Reader::read($registry, $value) === null) {
            throw new SchemaValidationError("no compiled schema registered for \"{$schemaName}\"");
        }
        return true;
    }

    /**
     * Whether `$value` -- data a program already holds -- matches the
     * definition `$name` in `$registry`: a struct schema, a `%shape` or a
     * named type (design/shapes-and-case.md). Only a check: `$value` is
     * never changed. Returns `true`, or throws the error saying why.
     */
    public static function check(mixed $value, Registry $registry, string $name): true
    {
        self::conform($value, $registry, $name);
        return true;
    }

    /**
     * What the `{:doc ... %{...}}` around the schema or named type `$name`
     * says about it (DXNS.md §1b): its annotations by name, `[]` when it
     * has none, `null` when `$registry` knows no such name. A schema
     * answers for its latest version. An enum's `values` are a map, as a
     * decode builds one, from each value to its own annotations by name.
     *
     * ```php
     * $registry = Schema::compile(Dextrin::decode('%{Port: {:doc :integer %{description: "A TCP port"}}}'), new Registry());
     * Schema::annotations($registry, 'Port'); // ['description' => 'A TCP port']
     * ```
     *
     * @param Registry $registry the compiled schemas and named types
     * @param string $name the schema's or named type's name
     * @return array<string, mixed>|null
     */
    public static function annotations(Registry $registry, string $name): ?array
    {
        $type = $registry->fetchTypeAlias($name);
        if ($type !== null) {
            $doc = $type->form === 'generic' ? $type->base : $type;
            return $doc !== null && $doc->form === 'doc' ? ($doc->notes ?? []) : [];
        }
        return $registry->fetchStructSchema($name)?->compiled->annotations;
    }

    /**
     * `$value` checked as {@see self::check()} checks it, returned with
     * every shape's missing defaults filled in, as a decode fills them;
     * throws the error saying why it does not match.
     */
    public static function conform(mixed $value, Registry $registry, string $name): mixed
    {
        $type = $registry->fetchTypeAlias($name);
        if ($type === null) {
            self::validate($value, $registry, $name);
            return $value;
        }
        $conformed = $type->conform($value, new Schema\Context($registry));
        if ($conformed === Schema\NoMatch::Instance) {
            throw new SchemaValidationError("value does not match \"{$name}\"", 'schema/type-mismatch');
        }
        return $conformed;
    }

    /**
     * Encode-time schema validation: checks `$value` -- ordinary PHP
     * data you're about to encode -- against a named, compiled schema
     * *before* encoding produces any output. The mirror image of
     * {@see self::validate()}, for data on its way out rather than in.
     * Returns the checked value, stripped of internal bookkeeping;
     * nothing is converted (DXNS.md §7).
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

    /**
     * The version discipline check ({@see Compat}, DXNS.md §8): `$old`'s
     * schemas against `$new`'s, as the problems -- a version whose
     * content changed without a new version, a version that went down --
     * and what the latest versions' materializers have to upgrade.
     *
     * @return array{problems: list<array<string, mixed>>, upgrades: list<array<string, mixed>>}
     */
    public static function compat(Registry $old, Registry $new): array
    {
        return Compat::check($old, $new);
    }
}
