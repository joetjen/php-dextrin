<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Text\Temporal;
use JOetjen\Dextrin\Value\DXNIp;
use JOetjen\Dextrin\Value\DXNUuid;

/**
 * The formats a refine's `format:` names (DXNS.md §2a, Jan,
 * 2026-10-20; as dextrin 71fbec0): a closed set, each checked the same
 * way in every implementation. A format DXN has a tag for is read by
 * that tag's reader; every other one by a grammar written as a regular
 * expression that PCRE, JavaScript (with the `u` flag) and PHP all read
 * alike -- the reference's, verbatim, matched with PHP's `u` modifier.
 *
 * `datetime` is read by {@see Temporal::parseDateTime()}, which holds an
 * instant in 64 bits of nanoseconds: a datetime outside 1677-2262 is
 * refused here, as everywhere in this port, where the reference takes it.
 *
 * @internal
 */
final class Format
{
    // ---- the grammars' parts, RFC 3986 and RFC 3987 ---------------------------

    private const LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
    private const HOSTNAME = '^' . self::LABEL . '(?:\.' . self::LABEL . ')*$';
    private const U_LABEL = '[\p{L}\p{N}](?:[\p{L}\p{N}\p{M}-]{0,61}[\p{L}\p{N}\p{M}])?';
    private const IDN_HOSTNAME = '^' . self::U_LABEL . '(?:\.' . self::U_LABEL . ')*$';

    private const ATEXT = '[\-A-Za-z0-9!#$%&\'*+/=?^_`{|}~]';
    /**
     * Past ASCII: every character an IRI or an internationalised address
     * adds, written so PCRE, JavaScript and PHP read it alike.
     */
    private const BEYOND = '[^\x00-\x7F]';
    private const QUOTED = '"(?:[\x20\x21\x23-\x5B\x5D-\x7E]|\\\\[\x20-\x7E])*"';
    private const LOCAL = '^(?:' . self::ATEXT . '+(?:\.' . self::ATEXT . '+)*|' . self::QUOTED . ')$';
    private const IDN_ATEXT = '(?:' . self::ATEXT . '|' . self::BEYOND . ')';
    private const IDN_LOCAL = '^(?:' . self::IDN_ATEXT . '+(?:\.' . self::IDN_ATEXT . '+)*|' . self::QUOTED . ')$';

    /** What an IRI adds to the characters a URI leaves unencoded: nothing for a URI. */
    private const URI_EXTRA = '';
    /** ... and every character past ASCII for an IRI. */
    private const IRI_EXTRA = '|' . self::BEYOND;

    private const VARSPEC = '(?:[A-Za-z0-9_]|%[0-9A-Fa-f]{2})(?:\.?(?:[A-Za-z0-9_]|%[0-9A-Fa-f]{2}))*(?::[1-9][0-9]{0,3}|\*)?';
    private const URI_TEMPLATE = '^(?:[^\x00-\x20"\'%<>\\\\^`{|}\x7F]|%[0-9A-Fa-f]{2}|\{[+#./;?&=,!@|]?' . self::VARSPEC . '(?:,' . self::VARSPEC . ')*\})*$';

    private const JSON_POINTER = '^(?:/(?:[^~/]|~[01])*)*$';
    private const RELATIVE_JSON_POINTER = '^(?:0|[1-9][0-9]*)(?:#|(?:/(?:[^~/]|~[01])*)*)$';

    private const SEMVER = '^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)'
        . '(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?'
        . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$';

    private const MAC = '^[0-9A-Fa-f]{2}([:-])[0-9A-Fa-f]{2}(?:\1[0-9A-Fa-f]{2}){4}(?:(?:\1[0-9A-Fa-f]{2}){2})?$';
    private const PORT = '^(?:0|[1-9][0-9]{0,4})$';

    /** A `[...]` host in a URI's authority, its inside captured. */
    private const BRACKETED_HOST = '//[^/?#]*?\[([^\]]*)\]';
    /** RFC 3986's IPvFuture, inside the brackets. */
    private const IP_FUTURE = '^v[0-9A-Fa-f]+\.[A-Za-z0-9._~!$&\'()*+,;=:-]+$';

    private const GRAMMARS = [
        'hostname' => self::HOSTNAME,
        'idn-hostname' => self::IDN_HOSTNAME,
        'uri-template' => self::URI_TEMPLATE,
        'json-pointer' => self::JSON_POINTER,
        'relative-json-pointer' => self::RELATIVE_JSON_POINTER,
        'semver' => self::SEMVER,
        'mac' => self::MAC,
    ];

    private const NAMES = [
        'date', 'time', 'datetime', 'local-datetime', 'duration', 'uuid', 'ipv4', 'ipv6', 'hostname', 'idn-hostname',
        'email', 'idn-email', 'uri', 'uri-reference', 'iri', 'iri-reference', 'uri-template', 'json-pointer',
        'relative-json-pointer', 'semver', 'mac', 'host-port',
    ];

    private function __construct()
    {
    }

    /**
     * Every format's name, sorted.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = self::NAMES;
        sort($names, SORT_STRING);
        return $names;
    }

    /** Whether `$text` is in the format named `$name`: a string of valid UTF-8 that its check takes. */
    public static function valid(string $name, string $text): bool
    {
        return mb_check_encoding($text, 'UTF-8') && self::check($name, $text);
    }

    private static function check(string $name, string $text): bool
    {
        return match ($name) {
            'date' => self::reads(static fn () => Temporal::parseDate($text)),
            'time' => self::reads(static fn () => Temporal::parseTime($text)),
            'datetime' => self::reads(static fn () => Temporal::parseDateTime($text)),
            'local-datetime' => self::reads(static fn () => Temporal::parseLocalDateTime($text)),
            'duration' => self::reads(static fn () => Temporal::parseDuration($text)),
            'uuid' => self::reads(static fn () => DXNUuid::fromString($text)),
            'ipv4' => self::isAddress($text, 4),
            'ipv6' => self::isAddress($text, 6),
            // RFC 1123's labels, the whole at most 253 codepoints.
            'hostname', 'idn-hostname' => mb_strlen($text, 'UTF-8') <= 253 && self::matches(self::GRAMMARS[$name], $text),
            'email' => self::isEmail($text, self::LOCAL, 'hostname'),
            'idn-email' => self::isEmail($text, self::IDN_LOCAL, 'idn-hostname'),
            'uri' => self::isUri($text, self::URI_EXTRA, true),
            'uri-reference' => self::isUri($text, self::URI_EXTRA, true) || self::isUri($text, self::URI_EXTRA, false),
            'iri' => self::isUri($text, self::IRI_EXTRA, true),
            'iri-reference' => self::isUri($text, self::IRI_EXTRA, true) || self::isUri($text, self::IRI_EXTRA, false),
            'host-port' => self::isHostPort($text),
            default => array_key_exists($name, self::GRAMMARS) && self::matches(self::GRAMMARS[$name], $text),
        };
    }

    /** Whether `$read` reads its text, the tag's reader throwing for text that is not its form. */
    private static function reads(\Closure $read): bool
    {
        try {
            $read();
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** Whether the grammar `$source` matches all of `$text`, read as UTF-8. */
    private static function matches(string $source, string $text): bool
    {
        return preg_match(self::pattern($source), $text) === 1;
    }

    /**
     * `$source` as a PCRE pattern with the `u` and `D` modifiers, delimited
     * by a byte no grammar holds: `$` is the string's end, so a final
     * newline is no part of a format (as the reference's `dollar_endonly`).
     */
    private static function pattern(string $source): string
    {
        return "\x01{$source}\x01uD";
    }

    private static function isAddress(string $text, int $version): bool
    {
        try {
            $ip = DXNIp::fromString($text);
        } catch (\InvalidArgumentException) {
            return false;
        }
        return $ip->prefix === null && $ip->version() === $version;
    }

    /**
     * A local part, `@`, then a hostname or an address literal: `[IPv4]`
     * or `[IPv6:...]` (RFC 5321 §4.1.3); the local part at most 64 bytes.
     */
    private static function isEmail(string $text, string $local, string $hostname): bool
    {
        $at = strrpos($text, '@');
        if ($at === false) {
            return false;
        }
        $name = substr($text, 0, $at);
        $domain = substr($text, $at + 1);
        return strlen($name) <= 64 && self::matches($local, $name)
            && (self::check($hostname, $domain) || self::isAddressLiteral($domain));
    }

    private static function isAddressLiteral(string $domain): bool
    {
        return match (true) {
            str_starts_with($domain, '[IPv6:') => self::isBracketed(substr($domain, 6), 6),
            str_starts_with($domain, '[') => self::isBracketed(substr($domain, 1), 4),
            default => false,
        };
    }

    /** Whether `$rest` is an address of `$version`, then `]`. */
    private static function isBracketed(string $rest, int $version): bool
    {
        return str_ends_with($rest, ']') && self::isAddress(substr($rest, 0, -1), $version);
    }

    /** A hostname, an IPv4 address or a bracketed IPv6 one, `:`, then a port up to 65535. */
    private static function isHostPort(string $text): bool
    {
        $at = strrpos($text, ':');
        if ($at === false) {
            return false;
        }
        $host = substr($text, 0, $at);
        $port = substr($text, $at + 1);
        $isHost = str_starts_with($host, '[')
            ? self::isAddressLiteral('[IPv6:' . substr($host, 1))
            : self::check('hostname', $host) || self::isAddress($host, 4);
        return $isHost && self::matches(self::PORT, $port) && (int) $port <= 65_535;
    }

    /**
     * The URI grammar, absolute or relative; `$extra` is what an IRI
     * adds to the characters a URI leaves unencoded.
     */
    private static function uriGrammar(string $extra, bool $absolute): string
    {
        $pct = '%[0-9A-Fa-f]{2}' . $extra;
        $u = '\-A-Za-z0-9._~!$&\'()*+,;=';
        $pchar = "(?:[{$u}:@]|{$pct})";
        $nc = "(?:[{$u}@]|{$pct})";
        $userinfo = "(?:(?:[{$u}:]|{$pct})*@)?";
        $host = '(?:\[[^\]]*\]|(?:[' . $u . ']|' . $pct . ')*)';
        $authority = "{$userinfo}{$host}(?::[0-9]*)?";
        $abempty = "(?:/{$pchar}*)*";
        $absolutePath = "/(?:{$pchar}+(?:/{$pchar}*)*)?";
        $rootless = "{$pchar}+(?:/{$pchar}*)*";
        $noscheme = "{$nc}+(?:/{$pchar}*)*";
        $tail = "(?:\\?(?:{$pchar}|[/?])*)?(?:#(?:{$pchar}|[/?])*)?";

        return $absolute
            ? "^[A-Za-z][A-Za-z0-9+.-]*:(?://{$authority}{$abempty}|{$absolutePath}|{$rootless}|){$tail}\$"
            : "^(?://{$authority}{$abempty}|{$absolutePath}|{$noscheme}|){$tail}\$";
    }

    /** The grammar, then any `[...]` host an IPv6 address or RFC 3986's IPvFuture. */
    private static function isUri(string $text, string $extra, bool $absolute): bool
    {
        if (!self::matches(self::uriGrammar($extra, $absolute), $text)) {
            return false;
        }
        preg_match_all(self::pattern(self::BRACKETED_HOST), $text, $hosts);
        foreach ($hosts[1] as $inside) {
            if (!self::isAddress($inside, 6) && !self::matches(self::IP_FUTURE, $inside)) {
                return false;
            }
        }
        return true;
    }
}
