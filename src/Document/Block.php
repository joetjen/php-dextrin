<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Document;

/**
 * A block of comment lines, as {@see Formatter} lays it out: the lines,
 * and whether a blank line came before it.
 *
 * @internal
 */
final class Block
{
    /** @param list<string> $lines */
    public function __construct(public readonly bool $blank, public array $lines)
    {
    }
}
