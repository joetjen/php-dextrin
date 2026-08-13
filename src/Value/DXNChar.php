<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `char` (`?a`) -- a single Unicode codepoint, distinct from a
 * 1-codepoint `string`.
 */
final class DXNChar implements DXNValueInterface
{
    private const DXN_TYPE = 'char';

    public readonly int $codepoint;

    public function __construct(int $codepoint)
    {
        if ($codepoint < 0 || $codepoint > 0x10FFFF) {
            throw new \InvalidArgumentException("not a valid Unicode codepoint: {$codepoint}");
        }
        $this->codepoint = $codepoint;
    }

    public function toString(): string
    {
        return mb_chr($this->codepoint, 'UTF-8');
    }

    public static function fromString(string $s): self
    {
        if (mb_strlen($s, 'UTF-8') !== 1) {
            throw new \InvalidArgumentException("not a single codepoint: {$s}");
        }
        // mb_strlen() === 1 above already guarantees a single,
        // well-formed codepoint, so mb_ord() here can't fail the way
        // it could on arbitrary input (it throws ValueError, doesn't
        // return false, on PHP 8+ for genuinely invalid input).
        return new self(mb_ord($s, 'UTF-8'));
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return $this->codepoint <=> $other->codepoint;
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->codepoint === $other->codepoint;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
