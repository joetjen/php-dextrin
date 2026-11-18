<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema\Docs;

/**
 * One version of a definition: its number (null for a named type known
 * in one plain version), the file it is in and the tool that generated
 * that file, what the file says about it, its body's rows, the bases its
 * head names (DXNS.md §4b), its parameters (a generic's, §5), its value,
 * and the annotations of a `{:doc}` around it (§1b; `Docs::undoc()`).
 *
 * @internal
 */
final class Version
{
    /**
     * @param ?array{tool: string, source: string} $provenance
     * @param list<Field|Row> $fields
     * @param list<array{relation: string, names: list<array{0: string, 1: ?int}>}> $bases
     * @param list<array{name: string, bound: ?At, default: ?At}> $params
     * @param array<string, mixed> $annotations
     */
    public function __construct(
        public readonly ?int $version,
        public readonly string $file,
        public readonly ?array $provenance,
        public readonly ?string $note,
        public readonly array $fields,
        public readonly array $bases,
        public readonly array $params,
        public readonly ?At $value,
        public readonly array $annotations = [],
    ) {
    }

    /** This version numbered `$version`. */
    public function numbered(int $version): self
    {
        return new self(
            $version,
            $this->file,
            $this->provenance,
            $this->note,
            $this->fields,
            $this->bases,
            $this->params,
            $this->value,
            $this->annotations,
        );
    }
}
