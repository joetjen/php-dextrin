<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Registry;

/**
 * The result of a successful {@see Registry::fetchStructSchema()} --
 * the compiled schema itself, plus the (possibly-updated) registry a
 * lazy resolver hit should be threaded forward through, so a
 * resolved-on-demand schema is only ever resolved once per registry
 * lineage.
 */
final class FetchedSchema
{
    public function __construct(
        public readonly Compiled $compiled,
        public readonly Registry $registry,
    ) {
    }
}
