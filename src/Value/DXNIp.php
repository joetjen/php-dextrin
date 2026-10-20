<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `ip` (`@ip "..."`, Jan, 2026-10-20): an IPv4 or IPv6 address, or a
 * prefix -- an address and a length. `$address` holds the raw bytes, 4
 * or 16; `$prefix` is the length, or null for an address. A prefix's
 * host bits are zero (RFC 9164). In `.dxnb` it is CBOR's tag 52 or 54,
 * raw bytes.
 *
 * The text is read and written here rather than by `inet_pton()` and
 * `inet_ntop()`, which differ from platform to platform: macOS's reads a
 * zone (`fe80::1%eth0`) and an IPv4 part with a leading zero, and writes
 * `::2` where the reference writes `::0.0.0.2`.
 */
final class DXNIp implements DXNValueInterface
{
    private const DXN_TYPE = 'ip';

    /** One IPv4 part: 0 to 255, no leading zero. */
    private const OCTET = '(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])';

    /** The raw bytes: 4 for IPv4, 16 for IPv6. */
    public readonly string $address;

    /** The prefix length, or null for an address. */
    public readonly ?int $prefix;

    /** A value from raw `$address` bytes and an optional `$prefix` length, checked. */
    public function __construct(string $address, ?int $prefix = null)
    {
        $size = strlen($address);
        if ($size !== 4 && $size !== 16) {
            throw new \InvalidArgumentException("DXNIp requires 4 or 16 address bytes, got {$size}");
        }
        if ($prefix !== null && ($prefix < 0 || $prefix > $size * 8 || !self::hostBitsZero($address, $prefix))) {
            throw new \InvalidArgumentException("not a valid ip prefix: /{$prefix} on " . self::addressText($address) . ', its host bits not zero or its length out of range');
        }
        $this->address = $address;
        $this->prefix = $prefix;
    }

    /**
     * Parses an address, or `address/length` -- strictly: no IPv4
     * shorthand (`10.1`), no zone (`%eth0`), no host bits set in a prefix.
     */
    public static function fromString(string $s): self
    {
        $parts = explode('/', $s);
        $address = self::addressBytes($parts[0]);
        if ($address === null || count($parts) > 2) {
            throw new \InvalidArgumentException("not a valid ip string: \"{$s}\"");
        }
        if (count($parts) === 1) {
            return new self($address);
        }
        if (preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $parts[1]) !== 1) {
            throw new \InvalidArgumentException("not a valid ip string: \"{$s}\"");
        }
        return new self($address, (int) $parts[1]);
    }

    /** IPv4 in dotted decimal, IPv6 as RFC 5952 writes it, then `/length` for a prefix. */
    public function toString(): string
    {
        $text = self::addressText($this->address);
        return $this->prefix === null ? $text : "{$text}/{$this->prefix}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** 4 or 6. */
    public function version(): int
    {
        return strlen($this->address) === 4 ? 4 : 6;
    }

    public function compareTo(mixed $other): int
    {
        if ($other instanceof self) {
            return [strlen($this->address), $this->address, $this->prefix ?? -1]
                <=> [strlen($other->address), $other->address, $other->prefix ?? -1];
        }
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->address === $other->address && $this->prefix === $other->prefix;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }

    // ---- reading -----------------------------------------------------------

    /** The raw bytes `$text` names, or null when it names none. */
    private static function addressBytes(string $text): ?string
    {
        if (str_contains($text, ':')) {
            return self::ipv6Bytes($text);
        }
        return self::ipv4Bytes($text);
    }

    private static function ipv4Bytes(string $text): ?string
    {
        if (preg_match('/^' . self::OCTET . '(?:\.' . self::OCTET . '){3}$/D', $text) !== 1) {
            return null;
        }
        return pack('C*', ...array_map(intval(...), explode('.', $text)));
    }

    /**
     * Eight groups of up to four hex digits, one run of them shortened to
     * `::`, the last two of them perhaps an IPv4 address.
     */
    private static function ipv6Bytes(string $text): ?string
    {
        $halves = explode('::', $text);
        if (count($halves) > 2) {
            return null;
        }
        $head = self::groups($halves[0], count($halves) === 1);
        $tail = count($halves) === 2 ? self::groups($halves[1], true) : [];
        if ($head === null || $tail === null) {
            return null;
        }
        $missing = 8 - count($head) - count($tail);
        if (count($halves) === 1 ? $missing !== 0 : $missing < 1) {
            return null;
        }
        $groups = [...$head, ...array_fill(0, count($halves) === 2 ? $missing : 0, 0), ...$tail];
        return pack('n*', ...$groups);
    }

    /**
     * The groups of one side of a `::`, the IPv4 address at the end as
     * two of them where `$last` says this side ends the address.
     *
     * @return list<int>|null
     */
    private static function groups(string $text, bool $last): ?array
    {
        if ($text === '') {
            return [];
        }
        $groups = [];
        $pieces = explode(':', $text);
        foreach ($pieces as $i => $piece) {
            if ($last && $i === count($pieces) - 1 && str_contains($piece, '.')) {
                $v4 = self::ipv4Bytes($piece);
                if ($v4 === null) {
                    return null;
                }
                /** @var array{1: int, 2: int} $pair */
                $pair = unpack('n2', $v4);
                $groups[] = $pair[1];
                $groups[] = $pair[2];
            } elseif (preg_match('/^[0-9a-fA-F]{1,4}$/D', $piece) === 1) {
                $groups[] = (int) hexdec($piece);
            } else {
                return null;
            }
        }
        return $groups;
    }

    // ---- writing -----------------------------------------------------------

    /**
     * An address as text: IPv4 dotted; IPv6 in lowercase hex, its longest
     * run of two or more zero groups (the first, on a tie) as `::`, and --
     * as the reference writes them -- an IPv4-mapped address
     * (`::ffff:a.b.c.d`) or an IPv4-compatible one other than `::` and
     * `::1` (`::a.b.c.d`) with its last 32 bits dotted.
     */
    private static function addressText(string $address): string
    {
        if (strlen($address) === 4) {
            return implode('.', array_map(ord(...), str_split($address)));
        }
        /** @var list<int> $groups */
        $groups = array_values(unpack('n8', $address) ?: []);
        $dotted = implode('.', array_map(ord(...), str_split(substr($address, 12))));
        $leading = array_slice($groups, 0, 6);
        if ($leading === [0, 0, 0, 0, 0, 0xffff]) {
            return "::ffff:{$dotted}";
        }
        if ($leading === [0, 0, 0, 0, 0, 0] && !($groups[6] === 0 && $groups[7] <= 1)) {
            return "::{$dotted}";
        }
        [$start, $length] = self::longestZeroRun($groups);
        $hex = array_map(dechex(...), $groups);
        if ($length < 2) {
            return implode(':', $hex);
        }
        return implode(':', array_slice($hex, 0, $start)) . '::' . implode(':', array_slice($hex, $start + $length));
    }

    /**
     * Where the longest run of zero groups starts, and how long it is.
     *
     * @param list<int> $groups
     * @return array{0: int, 1: int}
     */
    private static function longestZeroRun(array $groups): array
    {
        $best = [0, 0];
        $start = null;
        foreach ([...$groups, 1] as $i => $group) {
            if ($group === 0) {
                $start ??= $i;
            } elseif ($start !== null) {
                if ($i - $start > $best[1]) {
                    $best = [$start, $i - $start];
                }
                $start = null;
            }
        }
        return $best;
    }

    /** Whether every bit past the first `$length` of `$address` is zero. */
    private static function hostBitsZero(string $address, int $length): bool
    {
        $whole = intdiv($length, 8);
        $rest = $length % 8;
        $tail = substr($address, $whole + ($rest > 0 ? 1 : 0));
        if ($rest > 0 && (ord($address[$whole]) & (0xff >> $rest)) !== 0) {
            return false;
        }
        return trim($tail, "\0") === '';
    }
}
