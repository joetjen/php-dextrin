<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema\Docs;

/**
 * A row of a schema's or shape's body that is not a field (DXNS.md §4b,
 * §4c): a field left out of the bases (`- name`, kind `drop`), the
 * extras (`* K => V`, kind `extra`), or the closing (`- *`, kind
 * `closed`), each with its comments.
 *
 * @internal
 */
final class Row
{
    /** @param 'drop'|'extra'|'closed' $kind */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $name = null,
        public readonly ?At $key = null,
        public readonly ?At $type = null,
        public readonly string $doc = '',
    ) {
    }
}
