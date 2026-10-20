<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNMap;
use JOetjen\Dextrin\Value\Equality;

/**
 * Builds a decoded DXN `map` -- shared by the text
 * {@see \JOetjen\Dextrin\Text\Parser} and the binary
 * {@see \JOetjen\Dextrin\Binary\Decoder}, which need identical logic.
 *
 * **Every key is one DXN.md §1.6 allows** -- a string, keyword, integer,
 * uuid, objectid, date, bytes, symbol or char -- or the map is refused with
 * {@see KeyNotAllowed} (`key/not-allowed`).
 *
 * **A native PHP array when it can hold the map exactly, a
 * {@see DXNMap} when it cannot.** A native array holds a map whose keys
 * are all integers, keywords or strings, where no two of them coerce to
 * one array slot, no string key is one PHP would turn into an integer
 * (`"5"`), and the result is not shaped like a list (an empty map, or
 * one keyed `0..n-1`, which would read back as a DXN array). Anything
 * else is a DXNMap, which keys by canonical form and loses nothing.
 *
 * Within a native array a `DXNKeyword` reduces to its bare name string
 * (matching `map_entry`'s shorthand form, and keeping the common case --
 * `%{x: 1}` -- looking up as the ergonomic `$map['x']`), while a
 * genuinely *string*-typed key with the same text is marked with a single
 * leading {@see self::STRING_KEY_MARKER} byte, so `:x` and `"x"` stay two
 * keys. {@see self::toDxnMapKey()} is the reverse, used by every encoder
 * to recover which one a given PHP key was.
 */
final class MapBuilder
{
    /**
     * Marks a genuinely string-typed map key, distinguishing it from
     * a `DXNKeyword` key with the same text -- see this class's own
     * doc for why. A single NUL byte: it can't be the first character
     * of any *other* PHP-array-key-representable DXN value
     * (int/bool/float/nil all coerce to a plain `int` key or the bare
     * empty string, never a leading-NUL string), and prepending
     * exactly one byte is trivially, unambiguously reversible by
     * stripping exactly one byte back -- no escaping needed even for
     * a string that itself already starts with a NUL byte.
     *
     * The one residual ambiguity this can't resolve: a `keyword`
     * literally named starting with a NUL byte (only reachable via an
     * explicit `\x{0}` escape inside a quoted keyword literal) is
     * indistinguishable from a marked string key. Accepted as a
     * documented, exceedingly rare edge case, same spirit as the
     * int-vs-numeric-string collision this class already detects
     * rather than silently resolving.
     */
    private const STRING_KEY_MARKER = "\0";

    private function __construct()
    {
    }

    /**
     * @param list<array{0: mixed, 1: mixed}> $pairs
     * @param (\Closure(string): \Throwable) $errorFactory builds the
     * caller's own exception type (`Text\ParseError`/
     * `Binary\BinaryError`) from a message.
     * @return array<int|string, mixed>|DXNMap
     */
    public static function build(array $pairs, \Closure $errorFactory): array|DXNMap
    {
        MapKey::checkPairs($pairs);
        $result = [];
        /** @var array<int|string, string> $seenIdentity */
        $seenIdentity = [];
        foreach ($pairs as [$dxnKey, $value]) {
            if (!($dxnKey instanceof DXNKeyword || is_string($dxnKey) || is_int($dxnKey))) {
                return new DXNMap($pairs);
            }
            if (is_string($dxnKey) && self::isCanonicalIntegerString($dxnKey)) {
                // PHP would turn the array key `"5"` into the integer 5, and
                // the key would come back as one: a DXNMap keeps it a string.
                return new DXNMap($pairs);
            }
            $phpKey = self::mapKeyToPhpKey($dxnKey, $errorFactory);
            $identity = Equality::canonicalKey($dxnKey);
            if (isset($seenIdentity[$phpKey]) && $seenIdentity[$phpKey] !== $identity) {
                // Two distinct DXN keys PHP would coerce to one array key --
                // `1` and `"1"` -- so the map is one a native array cannot hold.
                return new DXNMap($pairs);
            }
            $seenIdentity[$phpKey] = $identity;
            $result[$phpKey] = $value;
        }
        // A native array shaped like a list reads back as a DXN array, so
        // an empty map, or one keyed 0..n-1, is a DXNMap too.
        return array_is_list($result) ? new DXNMap($pairs) : $result;
    }

    /** @param (\Closure(string): \Throwable) $errorFactory */
    private static function mapKeyToPhpKey(mixed $dxnKey, \Closure $errorFactory): int|string
    {
        return match (true) {
            $dxnKey instanceof DXNKeyword => $dxnKey->name,
            is_string($dxnKey) => self::markStringKey($dxnKey),
            is_int($dxnKey) => $dxnKey,
            default => throw $errorFactory(
                'map key of kind "' . Equality::kindOf($dxnKey) . '" has no PHP array-key representation',
            ),
        };
    }

    /**
     * Marks `$s` as a genuinely `string`-typed map key, the same rule
     * `mapKeyToPhpKey()` applies to a decoded `string` key -- exposed
     * for callers that build a map-shaped PHP array directly from a
     * known-string source without going through `build()`'s DXN-value
     * dispatch. The one caller today: {@see
     * \JOetjen\Dextrin\Schema\Validator}'s default (no custom
     * materializer registered) struct decode, which resolves a
     * `DXNStruct`'s field *names* into a bare associative array --
     * exactly the same "materialize a struct with no registered
     * module/materializer into a generic map" shape Elixir's own
     * default (`Atom.to_string/1` on each field name) and
     * node-dextrin's default both produce, keyed by the field name as
     * a genuine string, not a keyword. Left unmarked, those keys would
     * silently re-encode as `keyword` instead on the way back out --
     * diverging from what a schema-free reader of the same document in
     * either of those other two languages would produce.
     */
    public static function markStringKey(string $s): string
    {
        return self::isCanonicalIntegerString($s) ? $s : self::STRING_KEY_MARKER . $s;
    }

    /**
     * Whether PHP would auto-coerce `$s` to an `int` array key on its
     * own (e.g. `"1"`, `"0"`, `"-5"` -- but not `"01"`, `"1.0"`, `""`,
     * or a string past `int` range) -- left un-marked so it keeps
     * colliding with a bare `integer` key of the same value exactly as
     * before, rather than this class's own keyword/string marking
     * accidentally hiding that collision instead of reporting it.
     */
    private static function isCanonicalIntegerString(string $s): bool
    {
        return (string) (int) $s === $s;
    }

    /**
     * The reverse of {@see self::mapKeyToPhpKey()}'s `DXNKeyword`/
     * `string` handling, for `Text\Printer`/`Text\Formatter`/
     * `Binary\Encoder` to recover which one a bare `map`'s PHP array
     * key actually started out as. An `int` key needs no translation:
     * {@see self::build()} only ever leaves one there for a DXN integer,
     * since a string key PHP would coerce to an integer makes a
     * {@see DXNMap} instead.
     */
    public static function toDxnMapKey(int|string $phpKey): DXNKeyword|string|int
    {
        if (is_int($phpKey)) {
            return $phpKey;
        }
        if (str_starts_with($phpKey, self::STRING_KEY_MARKER)) {
            return substr($phpKey, strlen(self::STRING_KEY_MARKER));
        }
        return new DXNKeyword($phpKey);
    }
}
