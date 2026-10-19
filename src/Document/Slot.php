<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Document;

use JOetjen\Dextrin\Text\SyntaxNode;

/**
 * One entry as {@see Formatter} lays it out: the node, the comment blocks
 * above it, whether a blank line comes straight before it, and the
 * comment lines trailing it.
 *
 * @internal
 */
final class Slot
{
    /** @var list<string> */
    public array $trailing = [];

    /** @param list<Block> $above */
    public function __construct(public readonly SyntaxNode $node, public readonly array $above, public readonly bool $blank)
    {
    }
}
