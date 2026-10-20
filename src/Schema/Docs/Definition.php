<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema\Docs;

/**
 * A schema, shape or named type, with every version of it found.
 *
 * @internal
 */
final class Definition
{
    /**
     * @param 'schema'|'shape'|'type' $kind
     * @param array<string, string> $scope what a bare name in its file stands for
     * @param list<Version> $versions latest first
     */
    public function __construct(
        public readonly string $name,
        public readonly string $short,
        public readonly string $namespace,
        public readonly string $kind,
        public readonly ?string $internalTo,
        public readonly string $doc,
        public readonly array $scope,
        public readonly ?string $namespaceNote,
        public readonly array $versions,
    ) {
    }

    /**
     * This definition with `$versions` and `$namespaceNote`.
     *
     * @param list<Version> $versions
     */
    public function with(array $versions, ?string $namespaceNote): self
    {
        return new self(
            $this->name,
            $this->short,
            $this->namespace,
            $this->kind,
            $this->internalTo,
            $this->doc,
            $this->scope,
            $namespaceNote,
            $versions,
        );
    }
}
