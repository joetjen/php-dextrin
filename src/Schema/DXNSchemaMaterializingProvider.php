<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * A {@see DXNSchemaProvider} that additionally supplies the schema's
 * materializer -- called for every decoded struct of the schema,
 * whatever its version, with the schema's name, the version the fields
 * were read with and the checked fields; it answers the provider's own
 * class at the latest version. Without this, a provider's schema decodes
 * to the generic {@see \JOetjen\Dextrin\Value\DXNStruct}.
 */
interface DXNSchemaMaterializingProvider extends DXNSchemaProvider
{
    /** @param array<int|string, mixed> $fields */
    public function dxnMaterialize(string $name, int $version, array $fields): mixed;
}
