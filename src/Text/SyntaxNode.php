<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

/**
 * One node of a document's syntax tree, as {@see Parser::parseTree()}
 * records it: its kind, its first and last token (indices into the
 * tokens), the nodes inside it, and which of its tokens open it, key it,
 * or name it. The trivia between two tokens is the text between them.
 */
final class SyntaxNode
{
    public int $last = -1;
    public ?int $open = null;
    public ?int $key = null;
    public ?int $arrow = null;
    public ?int $name = null;

    /** @var list<SyntaxNode> */
    public array $children = [];

    public function __construct(public readonly string $kind, public readonly int $first)
    {
    }
}
