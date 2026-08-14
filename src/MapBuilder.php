<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\Equality;

/**
 * Builds a DXN `map` as a bare PHP array -- shared by the text
 * {@see \JOetjen\Dextrin\Text\Parser} and the binary
 * {@see \JOetjen\Dextrin\Binary\Decoder}, since both need identical
 * logic for the exact same reason: `map` is the one collection type
 * this port decodes to bare native `array` rather than a wrapper
 * class (see the project's own collections decision), and every
 * PHP-array-key coercion hazard that follows from that applies
 * equally regardless of which pipeline produced the raw key/value
 * pairs.
 *
 * Every key reduces to whatever PHP scalar it naturally coerces to --
 * everything follows PHP's own int/string/bool/float/null array-key
 * coercion (including PHP's own further auto-coercion of a canonical
 * decimal-integer *string* key to an `int` key, e.g. `"1"` and `1`
 * already share one slot before this class does anything at all),
 * **except** `DXNKeyword`, which needs one more bit of care: a
 * `DXNKeyword` reduces to its bare name string (matching
 * `map_entry`'s shorthand form exactly, and keeping the overwhelmingly
 * common case -- a keyword-keyed map, e.g. `%{x: 1}` -- looking up as
 * the ergonomic, unprefixed `$map['x']`), while a genuinely
 * *string*-typed key with the same text is marked with a single
 * leading {@see self::STRING_KEY_MARKER} byte first -- *unless* it's
 * already a canonical-integer-string PHP would coerce to an `int` key
 * on its own, which is left alone so it keeps colliding with a bare
 * `integer` key exactly as before (see the class-level doc's own
 * example of that, a deliberately-kept error case, not something this
 * class's own keyword/string marking is about).
 *
 * Without the mark, `:x` and `"x"` would collapse to the exact same
 * PHP array slot with no way to tell them apart again on encode --
 * which used to mean `Text\Printer`/`Text\Formatter` and
 * `Binary\Encoder` disagreed with each other about which one to
 * assume (the text side always guessed "keyword" for an
 * identifier-shaped key; the binary side always assumed "string"), so
 * the exact same decoded PHP value could produce a `.dxn` and a
 * `.dxnb` that decoded back to two different things.
 * {@see self::toDxnMapKey()} is the reverse direction, used by both
 * encoders to recover which one a given PHP key actually was.
 *
 * Two *distinct* DXN keys that would still coerce to the same PHP
 * slot after all of the above (`1` and `"1"` as a bare int/canonical-
 * integer-string pair) is a decode error, not silent data loss --
 * ordinary duplicate keys (the same key appearing twice) still follow
 * normal last-write-wins map semantics.
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
     * @return array<int|string, mixed>
     */
    public static function build(array $pairs, \Closure $errorFactory): array
    {
        $result = [];
        /** @var array<int|string, string> $seenIdentity */
        $seenIdentity = [];
        foreach ($pairs as [$dxnKey, $value]) {
            $phpKey = self::mapKeyToPhpKey($dxnKey, $errorFactory);
            $identity = Equality::canonicalKey($dxnKey);
            if (isset($seenIdentity[$phpKey]) && $seenIdentity[$phpKey] !== $identity) {
                throw $errorFactory(
                    'map keys collide once coerced to a PHP array key (' . self::describeKey($phpKey) . '): '
                    . 'two distinct DXN keys cannot share one map slot in this port',
                );
            }
            $seenIdentity[$phpKey] = $identity;
            $result[$phpKey] = $value;
        }
        return $result;
    }

    /** @param (\Closure(string): \Throwable) $errorFactory */
    private static function mapKeyToPhpKey(mixed $dxnKey, \Closure $errorFactory): int|string
    {
        return match (true) {
            $dxnKey instanceof DXNKeyword => $dxnKey->name,
            is_string($dxnKey) => self::markStringKey($dxnKey),
            is_int($dxnKey) => $dxnKey,
            is_bool($dxnKey) => $dxnKey ? 1 : 0,
            is_float($dxnKey) => (int) $dxnKey,
            $dxnKey === null => '',
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
     * key actually started out as. An `int` key needs no translation
     * -- it only ever came from a `DXNInteger`/`bool`/`float`/
     * canonical-integer-`string` key to begin with, and re-encoding it
     * as a plain integer is correct either way (see this class's own
     * doc on why a canonical-integer-string key is deliberately left
     * to collide with an `integer` key rather than marked apart).
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

    private static function describeKey(int|string $phpKey): string
    {
        if (is_int($phpKey)) {
            return (string) $phpKey;
        }
        $unmarked = str_starts_with($phpKey, self::STRING_KEY_MARKER) ? substr($phpKey, 1) : $phpKey;
        return "\"{$unmarked}\"";
    }
}
