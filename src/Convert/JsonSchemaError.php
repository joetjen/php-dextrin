<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Convert;

/**
 * A JSON Schema the converter cannot read ({@see JsonSchema}): text that
 * is not JSON, a reference to a document not given, an unknown draft, a
 * value that is no schema.
 */
final class JsonSchemaError extends \RuntimeException
{
}
