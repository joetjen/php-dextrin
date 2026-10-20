<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema\Docs;

use JOetjen\Dextrin\Text\SyntaxNode;
use JOetjen\Dextrin\Text\Token;

/**
 * A node of a schema document's tree, with the source and tokens it is in.
 *
 * @internal
 */
final class At
{
    /** @param list<Token> $tokens */
    public function __construct(
        public readonly string $source,
        public readonly array $tokens,
        public readonly SyntaxNode $node,
    ) {
    }

    /** The same document, another node. */
    public function at(SyntaxNode $node): self
    {
        return new self($this->source, $this->tokens, $node);
    }
}
