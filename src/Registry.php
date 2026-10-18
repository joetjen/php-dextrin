<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

use JOetjen\Dextrin\Schema\Compiled;
use JOetjen\Dextrin\Schema\FetchedSchema;
use JOetjen\Dextrin\Schema\TypeExpr;
use JOetjen\Dextrin\Value\DXNStruct;

/**
 * Extension point for both `struct` and `custom-tag` decoding/encoding.
 * No entry for a given name -> decode falls back to an opaque
 * {@see \JOetjen\Dextrin\Value\DXNStruct}/{@see \JOetjen\Dextrin\Value\DXNCustomTag}
 * -- never a hard failure, since neither type requires a registration
 * to be representable at all.
 *
 * Struct entries only ever come from a compiled `.dxns` schema
 * ({@see \JOetjen\Dextrin\Schema\Compiler}) plus an optional
 * materializer layered on top -- there is no `putStruct` that
 * hand-writes a struct's shape directly, because `struct` is
 * schema-dependent by design: the schema supplies field
 * names/order/types, a materializer only decides what nicer decoded
 * shape to produce from an already-schema-validated field map.
 *
 * Immutable-by-convention: every `put*` method returns a *new*
 * `Registry`, never mutates `$this` -- threaded explicitly through
 * `decode`/`encode`/`Schema::compile`, never a global or a class with
 * hidden state.
 *
 * **Deliberate omission vs. the Elixir and JS ports**: no
 * `trusted`/`putTrusted`. Both mirror it purely to gate whether
 * `keyword` decodes as an interned atom/`Symbol.for` (trusted) or a
 * plain wrapper (untrusted) -- this port's `keyword` always decodes as
 * {@see \JOetjen\Dextrin\Value\DXNKeyword} regardless, since PHP has
 * no atom table or global symbol registry whose exhaustion untrusted
 * input could threaten in the first place (see `DXNKeyword`'s own
 * doc).
 */
final class Registry
{
    /** @var array<string, \Closure(mixed): mixed> tag name -> decoder. */
    private array $tags = [];

    /** @var array<class-string, array{0: string, 1: \Closure(object): mixed}> class -> [tag name, encoder]. */
    private array $tagEncoders = [];

    /** @var array<string, array<int, Compiled>> struct name -> version -> compiled schema. */
    private array $structs = [];

    /** @var array<string, \Closure(array<string, mixed>): mixed> struct name -> materializer. */
    private array $materializers = [];

    /** @var (\Closure(string): ?Compiled)|null */
    private ?\Closure $resolver = null;

    /** @var array<string, TypeExpr> named type -> compiled type expression. */
    private array $typeAliases = [];

    /** @var array<string, class-string> struct name -> PHP class. */
    private array $structModules = [];

    private function clone(): self
    {
        return clone $this;
    }

    /**
     * @param \Closure(mixed): mixed $decoder
     */
    public function putTag(string $name, \Closure $decoder): self
    {
        $copy = $this->clone();
        $copy->tags[$name] = $decoder;
        return $copy;
    }

    /** @return (\Closure(mixed): mixed)|null */
    public function fetchTag(string $name): ?\Closure
    {
        return $this->tags[$name] ?? null;
    }

    /**
     * The reverse of {@see self::putTag()}: registers how to turn an
     * application value (keyed by its class) back into `@name value`
     * on encode -- decode-side dispatch happens by wire tag name,
     * encode needs to dispatch by PHP class instead, so the two
     * directions need separate tables.
     *
     * @param class-string $class
     * @param \Closure(object): mixed $encoder
     */
    public function putTagEncoder(string $class, string $name, \Closure $encoder): self
    {
        $copy = $this->clone();
        $copy->tagEncoders[$class] = [$name, $encoder];
        return $copy;
    }

    /**
     * @param class-string $class
     * @return array{0: string, 1: \Closure(object): mixed}|null
     */
    public function fetchTagEncoder(string $class): ?array
    {
        return $this->tagEncoders[$class] ?? null;
    }

    /**
     * `$compiled` as version `$compiled->version` of the schema `$name`,
     * whether or not that version was loaded already -- the unchecked
     * setter a resolver's answer goes through. Loading a document's
     * schemas goes through {@see addStructSchema()}, a hot swap through
     * {@see replaceStructSchema()}.
     */
    public function putStructSchema(string $name, Compiled $compiled): self
    {
        $copy = $this->clone();
        $copy->structs[$name][$compiled->version] = $compiled;
        return $copy;
    }

    /**
     * Loads `$compiled` as a new version of the schema `$name`. A version
     * that is already loaded is refused as `schema/duplicate-version`
     * (DXN.md §4.8): two documents both claiming to be `User` v3 is a
     * mistake, not an update.
     *
     * @throws DXNError
     */
    public function addStructSchema(string $name, Compiled $compiled): self
    {
        if ($this->isLoaded($name, $compiled->version)) {
            throw new DXNError(
                "schema \"{$name}\" version {$compiled->version} is already loaded",
                stage: 'schema',
                category: 'schema/duplicate-version',
            );
        }
        return $this->putStructSchema($name, $compiled);
    }

    /**
     * Replaces version `$compiled->version` of the schema `$name` -- the
     * hot swap of a running system. **Replace only replaces**: a version
     * that is not loaded is refused as `schema/not-loaded`, so a typo in
     * a hot swap never quietly adds a version instead.
     *
     * @throws DXNError
     */
    public function replaceStructSchema(string $name, Compiled $compiled): self
    {
        if (!$this->isLoaded($name, $compiled->version)) {
            throw new DXNError(
                "schema \"{$name}\" version {$compiled->version} is not loaded, so there is nothing to replace",
                stage: 'schema',
                category: 'schema/not-loaded',
            );
        }
        return $this->putStructSchema($name, $compiled->withName($name));
    }

    private function isLoaded(string $name, int $version): bool
    {
        return isset($this->structs[$name][$version]);
    }

    /** @param \Closure(array<string, mixed>): mixed $materializer */
    public function putStructMaterializer(string $name, \Closure $materializer): self
    {
        $copy = $this->clone();
        $copy->materializers[$name] = $materializer;
        return $copy;
    }

    /** @param \Closure(string): ?Compiled $resolver */
    public function putResolver(\Closure $resolver): self
    {
        $copy = $this->clone();
        $copy->resolver = $resolver;
        return $copy;
    }

    /**
     * Looks up a compiled schema by struct name: the latest version, or
     * with `$version` exactly that one. Only the latest consults the
     * lazy resolver (if any) on a miss, caching the result. Whether
     * schemas are all compiled up front or resolved on demand (a
     * resolver reading a file, calling a schema service, ...) is
     * entirely the caller's choice -- this is what makes both paths look
     * identical to everything else. **A named version never falls back
     * to another**: a struct is only ever read with the schema it was
     * written against.
     */
    public function fetchStructSchema(string $name, ?int $version = null): ?FetchedSchema
    {
        $versions = $this->structs[$name] ?? [];
        if ($version !== null) {
            return isset($versions[$version]) ? new FetchedSchema($versions[$version], $this) : null;
        }
        if ($versions !== []) {
            return new FetchedSchema($versions[max(array_keys($versions))], $this);
        }
        if ($this->resolver === null) {
            return null;
        }
        $resolved = ($this->resolver)($name);
        if ($resolved === null) {
            return null;
        }
        return new FetchedSchema($resolved, $this->putStructSchema($name, $resolved));
    }

    /**
     * `$struct` as it is written here: carrying the latest loaded version
     * of its schema (DXN.md §4.8), whatever version it says itself. A
     * struct whose name has no schema keeps its own version, or none.
     * Every writer goes through this -- text, the formatter and binary --
     * so encoding never writes a version other than the latest, and never
     * writes one no schema stands behind.
     */
    public function asWritten(DXNStruct $struct): DXNStruct
    {
        $found = $this->fetchStructSchema($struct->name);
        return $found === null ? $struct : $struct->withVersion($found->compiled->version);
    }

    /** @return (\Closure(array<string, mixed>): mixed)|null */
    public function fetchMaterializer(string $name): ?\Closure
    {
        return $this->materializers[$name] ?? null;
    }

    public function putTypeAlias(string $name, TypeExpr $typeExpr): self
    {
        $copy = $this->clone();
        $copy->typeAliases[$name] = $typeExpr;
        return $copy;
    }

    public function fetchTypeAlias(string $name): ?TypeExpr
    {
        return $this->typeAliases[$name] ?? null;
    }

    /** @return array<string, TypeExpr> */
    public function typeAliases(): array
    {
        return $this->typeAliases;
    }

    /**
     * Declares which PHP class a schema's data is expected to be --
     * used only for `{form: 'reference', name}` checks during
     * encode-time validation. Decode-time reference checks don't need
     * this: they carry an internal provenance marker regardless of
     * materializer shape ({@see \JOetjen\Dextrin\Schema\Validated}).
     *
     * @param class-string $class
     */
    public function putStructModule(string $name, string $class): self
    {
        $copy = $this->clone();
        $copy->structModules[$name] = $class;
        return $copy;
    }

    /** @return class-string|null */
    public function fetchStructModule(string $name): ?string
    {
        return $this->structModules[$name] ?? null;
    }

    /**
     * The reverse of {@see self::fetchStructModule()}: given an
     * application value's own class, finds the schema name it was
     * registered under (if any) -- what lets automatic encode-time
     * validation recognize a plain PHP class instance as "this is a
     * Foo" without being told so explicitly.
     *
     * @param class-string $class
     */
    public function fetchSchemaNameForModule(string $class): ?string
    {
        foreach ($this->structModules as $name => $registered) {
            if ($registered === $class) {
                return $name;
            }
        }
        return null;
    }
}
