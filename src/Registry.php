<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

use JOetjen\Dextrin\Schema\Compiled;
use JOetjen\Dextrin\Schema\FetchedSchema;
use JOetjen\Dextrin\Schema\TypeExpr;

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

    /** @var array<string, Compiled> struct name -> compiled schema. */
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

    public function putStructSchema(string $name, Compiled $compiled): self
    {
        $copy = $this->clone();
        $copy->structs[$name] = $compiled;
        return $copy;
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
     * Looks up a compiled schema by struct name, consulting the lazy
     * resolver (if any) on a miss and caching the result. Whether
     * schemas are all compiled up front or resolved on demand (a
     * resolver reading a file, calling a schema service, ...) is
     * entirely the caller's choice -- this is what makes both paths
     * look identical to everything else, which never needs to know
     * which one is in play.
     */
    public function fetchStructSchema(string $name): ?FetchedSchema
    {
        $cached = $this->structs[$name] ?? null;
        if ($cached !== null) {
            return new FetchedSchema($cached, $this);
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
