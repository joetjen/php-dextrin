<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `bits` (DXN.md §1.4): a run of bits that is not whole bytes --
 * `@bits {"oA==" 3}` in text, `T_BITS` around `[bytes, count]` in
 * `.dxnb`. PHP has no bitstring, so this holds the bytes, the unused low
 * bits of the last one zero, and how many bits are the value.
 *
 * **Bits and bytes are one value space**: a run of whole bytes is bytes,
 * so {@see DXNBits::from()} answers a {@see DXNBytes} for one, and the
 * constructor refuses it. Mirrors the Elixir reference's bitstring in a
 * `Dextrin.Bytes`.
 */
final class DXNBits implements DXNValueInterface
{
    private const DXN_TYPE = 'bits';

    public readonly string $data;

    public readonly int $bitLength;

    /**
     * @param string $data the bits, from the high bit of the first byte
     * @param int $bitLength how many of them are the value; not a multiple of 8
     */
    public function __construct(string $data, int $bitLength)
    {
        $made = self::parts($data, $bitLength);
        if ($made === null || $bitLength % 8 === 0) {
            throw new \InvalidArgumentException('DXNBits holds a run of bits that is not whole bytes: data of exactly ceil(bitLength / 8) bytes');
        }
        $this->data = $made;
        $this->bitLength = $bitLength;
    }

    /**
     * The value `$data` and `$bitLength` write: bits, or bytes when the
     * count is a whole number of them. An `\InvalidArgumentException` when
     * they write none (a count the data does not match).
     */
    public static function from(string $data, int $bitLength): self|DXNBytes
    {
        $made = self::parts($data, $bitLength);
        if ($made === null) {
            throw new \InvalidArgumentException("bits wants ceil({$bitLength} / 8) bytes and a non-negative count, got " . strlen($data) . ' bytes');
        }
        return $bitLength % 8 === 0 ? new DXNBytes($made) : new self($made, $bitLength);
    }

    /**
     * `$data` with the unused low bits of its last byte cleared, or null
     * when it is not exactly the bytes `$bitLength` needs.
     */
    private static function parts(string $data, int $bitLength): ?string
    {
        if ($bitLength < 0 || strlen($data) !== intdiv($bitLength + 7, 8)) {
            return null;
        }
        $used = $bitLength % 8;
        if ($used === 0) {
            return $data;
        }
        $last = strlen($data) - 1;
        $data[$last] = chr(ord($data[$last]) & ((0xff << (8 - $used)) & 0xff));
        return $data;
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return [$this->data, $this->bitLength] <=> [$other->data, $other->bitLength];
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->data === $other->data && $this->bitLength === $other->bitLength;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
