<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Text\Parser;

/**
 * Compiles and registers one {@see DXNSchemaProvider} into a
 * {@see Registry}, in order:
 *
 *   1. Decodes and compiles the provider's own `dxnSchema()` source
 *      into the registry. Every named type and every `%schema{}` entry
 *      that document defines gets folded in, not only the one
 *      `dxnSchemaName()` points at.
 *   2. Looks up `dxnSchemaName()` in the now-compiled registry -- if
 *      it isn't there (the provider declared a name its own document
 *      doesn't define), throws a specific error naming both, rather
 *      than silently registering nothing.
 *   3. Associates `dxnClass()` with that schema name
 *      ({@see Registry::putStructModule()}) -- what lets
 *      `encode()`/`encodeBinary()` serialize instances of it directly
 *      and lets `{form: 'reference', name}` checks recognize it.
 *   4. If the provider implements {@see DXNSchemaMaterializingProvider},
 *      registers its `dxnMaterialize()` ({@see
 *      Registry::putStructMaterializer()}). If not, decoding this
 *      schema falls back to the default plain field map.
 */
final class Provider
{
    private function __construct()
    {
    }

    /** @param \Closure(mixed, Registry): Registry $compile */
    public static function registerProvider(Registry $registry, DXNSchemaProvider $provider, \Closure $compile): Registry
    {
        $doc = Parser::parse($provider->dxnSchema());
        $compiled = $compile($doc, $registry);

        $name = $provider->dxnSchemaName();
        $found = $compiled->fetchStructSchema($name);
        if ($found === null) {
            throw new \RuntimeException(
                "provider's dxnSchemaName() returned \"{$name}\", but its own dxnSchema() document has no such schema entry",
            );
        }
        $compiled = $found->registry;

        $compiled = $compiled->putStructModule($name, $provider->dxnClass());
        if ($provider instanceof DXNSchemaMaterializingProvider) {
            $compiled = $compiled->putStructMaterializer($name, $provider->dxnMaterialize(...));
        }
        return $compiled;
    }
}
