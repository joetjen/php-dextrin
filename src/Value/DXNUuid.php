<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `uuid` (`@uuid "..."`) -- 16 raw bytes, RFC 4122. PHP strings
 * are binary-safe natively, so the raw-bytes field itself needs no
 * `Uint8Array`-style wrapper the way JS needed one.
 */
final class DXNUuid implements DXNValueInterface
{
    private const DXN_TYPE = 'uuid';

    /** Exactly 16 raw bytes. */
    public readonly string $bytes;

    public function __construct(string $bytes)
    {
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('DXNUuid requires exactly 16 bytes, got ' . strlen($bytes));
        }
        $this->bytes = $bytes;
    }

    /** Canonical lowercase 36-char form, e.g. `550e8400-e29b-41d4-a716-446655440000`. */
    public function toString(): string
    {
        $hex = bin2hex($this->bytes);
        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** Case-insensitive on parse; {@see self::toString()} always lowercases. */
    public static function fromString(string $s): self
    {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $s)) {
            throw new \InvalidArgumentException("not a valid UUID string: {$s}");
        }
        $bytes = hex2bin(str_replace('-', '', $s));
        if ($bytes === false) {
            throw new \InvalidArgumentException("not a valid UUID string: {$s}");
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
