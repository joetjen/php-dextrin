<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `bytes` (`@bytes "..."`, base64 in text) -- raw binary data.
 * Same "string is also bare binary" ambiguity the Elixir and JS ports
 * share; wrapped rather than a bare PHP string purely so `bytes`
 * stays distinguishable from `string` on encode.
 */
final class DXNBytes implements DXNValueInterface
{
    private const DXN_TYPE = 'bytes';

    public readonly string $data;

    public function __construct(string $data)
    {
        $this->data = $data;
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return $this->data <=> $other->data;
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->data === $other->data;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
