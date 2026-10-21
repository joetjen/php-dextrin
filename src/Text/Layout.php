<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

use JOetjen\Dextrin\Registry;

/**
 * What {@see Formatter} carries from one level to the next: the spaces per
 * level, the width a nested collection's one line must fit in, the room
 * left on the current line after its indent and key, whether this is
 * inside a one-line collection, and the registry.
 *
 * @internal
 */
final class Layout
{
    public function __construct(
        public readonly int $indentSize,
        public readonly int $width,
        public readonly int $room,
        public readonly bool $flat,
        public readonly ?Registry $registry,
    ) {
    }

    /** This layout with the room a value has after `$used` columns of its line. */
    public function withRoom(int $used): self
    {
        return new self($this->indentSize, $this->width, $this->width - $used, $this->flat, $this->registry);
    }

    /** This layout inside a one-line collection. */
    public function oneLine(): self
    {
        return new self($this->indentSize, $this->width, $this->room, true, $this->registry);
    }
}
