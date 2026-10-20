<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `objectid` (`@objectid "..."`, Jan, 2026-10-20): BSON's 12-byte
 * ObjectId, built in like `uuid` -- readable in text, its raw bytes in
 * `.dxnb` (tag 221). The value holds the bytes; the 24 hex digits exist
 * only at the text boundary.
 */
final class DXNObjectId implements DXNValueInterface
{
    private const DXN_TYPE = 'objectid';

    /** Exactly 12 raw bytes. */
    public readonly string $bytes;

    public function __construct(string $bytes)
    {
        if (strlen($bytes) !== 12) {
            throw new \InvalidArgumentException('DXNObjectId requires exactly 12 bytes, got ' . strlen($bytes));
        }
        $this->bytes = $bytes;
    }

    /** The 24 lowercase hex digits, e.g. `507f1f77bcf86cd799439011`. */
    public function toString(): string
    {
        return bin2hex($this->bytes);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** 24 hex digits, either case; {@see self::toString()} always lowercases. */
    public static function fromString(string $s): self
    {
        $bytes = preg_match('/^[0-9a-fA-F]{24}$/D', $s) === 1 ? hex2bin($s) : false;
        if ($bytes === false) {
            throw new \InvalidArgumentException("not a valid objectid string: \"{$s}\"");
        }
        return new self($bytes);
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return $this->bytes <=> $other->bytes;
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->bytes === $other->bytes;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
