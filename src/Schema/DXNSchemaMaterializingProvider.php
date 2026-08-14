<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * A {@see DXNSchemaProvider} that additionally supplies a materializer
 * -- how to turn an already-schema-validated field map into whatever
 * decoded shape the provider's own class prefers. Without this, a
 * provider's schema decodes to the default plain field array.
 */
interface DXNSchemaMaterializingProvider extends DXNSchemaProvider
{
    /** @param array<string, mixed> $resolved */
    public function dxnMaterialize(array $resolved): mixed;
}
