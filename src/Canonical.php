<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

use JOetjen\Dextrin\Text\Printer;
use JOetjen\Dextrin\Text\Temporal;
use JOetjen\Dextrin\Value\DXNBits;
use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNDuration;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTime;
use JOetjen\Dextrin\Value\DXNTimestamp;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUri;
use JOetjen\Dextrin\Value\DXNUuid;

/**
 * The canonical form of a value (DXN.md §1.5): one `.dxn` rendering,
 * fixed down to the byte, so values from different implementations can
 * be compared as text.
 *
 * **Not {@see Dextrin::encode()}.** The printer chooses what reads best;
 * this chooses nothing. One space between items, maps and sets in
 * canonical order (by code point of each canonical form, which is the
 * byte order of their UTF-8, so `strcmp` is exactly right), floats by
 * one exact algorithm, strings with one fixed set of escapes: the rules
 * every DXN implementation follows, which is what lets the Dextrin
 * Conformance Suite compare two strings instead of two values.
 *
 * **Nothing is normalised.** A decimal keeps its scale and its sign, a
 * rational is not reduced. Keyed structs and ordered maps keep the order
 * they hold: in a `%schema` body the order of entries is the order of
 * the fields.
 */
final class Canonical
{
    private function __construct()
    {
    }

    /**
     * The canonical form of `$value`.
     *
     * @throws DXNError `encode/unsupported` for a value with no DXN meaning.
     */
    public static function print(mixed $value): string
    {
        try {
            return self::text($value);
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'encode');
        }
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => 'nil',
            $value === true => 'true',
            $value === false => 'false',
            $value instanceof DXNInteger => $value->toString(),
            is_int($value) => (string) $value,
            is_float($value) => self::float($value),
            is_string($value) => self::quoted($value),
            $value instanceof DXNDecimal => self::decimal($value),
            $value instanceof DXNRational => "{$value->numerator}/{$value->denominator}",
            $value instanceof DXNChar => '?' . self::charBody($value->codepoint),
            $value instanceof DXNSymbol => Printer::isBareIdentifier($value->name)
                ? $value->name
                : throw UnsupportedValue::symbol($value->name),
            $value instanceof DXNKeyword => self::keyword($value->name),
            $value instanceof DXNBytes => '@bytes ' . self::quoted(base64_encode($value->data)),
            $value instanceof DXNBits => '@bits {' . self::quoted(base64_encode($value->data)) . ' ' . $value->bitLength . '}',
            $value instanceof DXNList => '[' . self::items($value->items) . ']',
            $value instanceof DXNTuple => '{' . self::items($value->items) . '}',
            $value instanceof DXNSet => '@{' . implode(' ', self::sorted(array_map(self::text(...), $value->items))) . '}',
            $value instanceof DXNSortedSet => '@sorted-set @{' . implode(' ', self::sorted(array_map(self::text(...), $value->items))) . '}',
            $value instanceof DXNOrderedMap => '@ordered %{' . self::entries($value->pairs, false) . '}',
            $value instanceof \JOetjen\Dextrin\Value\DXNMap => '%{' . self::entries($value->pairs(), true) . '}',
            $value instanceof DXNStruct => self::struct($value),
            $value instanceof DXNDate => '~D[' . Temporal::formatDate($value) . ']',
            $value instanceof DXNTime => '~T[' . self::trimmed(Temporal::formatTime($value)) . ']',
            $value instanceof DXNTimestamp => '~U[' . self::trimmedInstant(Temporal::formatInstant($value)) . ']',
            $value instanceof DXNDateTime => '@datetime ' . self::quoted(self::trimmedInstant(Temporal::formatOffsetDateTime($value))),
            $value instanceof \DateTimeInterface => self::text(DXNDateTime::fromNative($value)),
            $value instanceof DXNDuration => '@duration ' . self::quoted(Temporal::formatDuration($value)),
            $value instanceof DXNUuid => '@uuid ' . self::quoted(strtolower($value->toString())),
            $value instanceof DXNUri => '@uri ' . self::quoted($value->value),
            $value instanceof DXNRegex => '~r/' . $value->pattern . '/' . self::regexFlags($value->flags),
            $value instanceof DXNCustomTag => '@' . $value->name . ' ' . self::text($value->value),
            is_array($value) && array_is_list($value) => '@array[' . self::items($value) . ']',
            is_array($value) => '%{' . self::entries(self::mapPairs($value), true) . '}',
            default => throw UnsupportedValue::of($value),
        };
    }

    // ---- collections ---------------------------------------------------

    /** @param array<mixed> $values */
    private static function items(array $values): string
    {
        return implode(' ', array_map(self::text(...), $values));
    }

    /**
     * Canonical order: by code point, which is the byte order of UTF-8.
     *
     * @param list<string> $texts
     * @return list<string>
     */
    private static function sorted(array $texts): array
    {
        usort($texts, strcmp(...));
        return $texts;
    }

    /**
     * A native map's entries as DXN keys and values, recovering each key
     * the way {@see MapBuilder} stored it.
     *
     * @param array<int|string, mixed> $map
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function mapPairs(array $map): array
    {
        $pairs = [];
        foreach ($map as $key => $value) {
            $pairs[] = [MapBuilder::toDxnMapKey($key), $value];
        }
        return $pairs;
    }

    /**
     * Entries as written, in canonical order of their keys when `$sort`,
     * in the order held otherwise: shorthand for a keyword whose name is
     * an identifier, the arrow for every other key.
     *
     * @param array<array{0: mixed, 1: mixed}> $pairs
     */
    private static function entries(array $pairs, bool $sort): string
    {
        $rows = [];
        foreach ($pairs as [$key, $value]) {
            $keyText = self::text($key);
            $written = $key instanceof DXNKeyword && Printer::isBareIdentifier($key->name)
                ? "{$key->name}: " . self::text($value)
                : "{$keyText} => " . self::text($value);
            $rows[] = [$keyText, $written];
        }
        if ($sort) {
            usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        }
        return implode(' ', array_map(static fn (array $row): string => $row[1], $rows));
    }

    /**
     * A keyed struct's extra fields follow its declared ones in the order
     * they were written; a positional struct's follow its items in
     * canonical order of their keys (DXN.md §1.5).
     */
    private static function struct(DXNStruct $value): string
    {
        $entry = static fn (mixed $key, mixed $field): string => !is_string($key)
            ? self::text($key) . ' => ' . self::text($field)
            : (Printer::isBareIdentifier($key) ? "{$key}: " . self::text($field) : self::quoted($key) . ' => ' . self::text($field));
        $head = DXNStruct::head($value->name, $value->version);
        if ($value->isKeyed()) {
            $written = array_map(static fn (array $pair): string => $entry($pair[0], $pair[1]), [...$value->pairs(), ...$value->extra]);
            return $head . '{' . implode(' ', $written) . '}';
        }
        $byKey = [];
        foreach ($value->extra as [$key, $field]) {
            $byKey[self::text($key)] = $entry($key, $field);
        }
        $extras = array_map(static fn (string $key): string => $byKey[$key], self::sorted(array_map('strval', array_keys($byKey))));
        $body = array_values(array_filter([self::items($value->values()), ...$extras], static fn (string $part): bool => $part !== ''));
        return $head . '[' . implode(' ', $body) . ']';
    }

    private static function keyword(string $name): string
    {
        return Printer::isBareIdentifier($name) ? ":{$name}" : ':' . self::quoted($name);
    }

    // ---- numbers ---------------------------------------------------------

    /**
     * A float by DXN.md §1.5: the shortest digits that read back exactly,
     * ECMAScript's thresholds, `.0` on a whole number, no `+` in an
     * exponent, and the sign of negative zero kept. The digits are found
     * by asking for more until they read back exactly, so no ini setting
     * (`precision`, `serialize_precision`) can change them.
     */
    private static function float(float $n): string
    {
        if (is_nan($n)) {
            return 'NaN';
        }
        if ($n === INF) {
            return 'Infinity';
        }
        if ($n === -INF) {
            return '-Infinity';
        }
        // `sprintf` drops the sign of negative zero; dividing by it does not.
        $negative = $n < 0 || ($n === 0.0 && fdiv(1, $n) < 0);
        $magnitude = abs($n);
        if ($magnitude === 0.0) {
            return ($negative ? '-' : '') . '0.0';
        }
        for ($places = 0; $places <= 17; $places++) {
            $written = sprintf("%.{$places}e", $magnitude);
            if ((float) $written === $magnitude) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $written);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        return ($negative ? '-' : '') . self::laidOut($digits === '' ? '0' : $digits, (int) $exponent + 1);
    }

    /** The digits with value 0.d1..dk × 10^n, laid out by DXN.md §1.5. */
    private static function laidOut(string $digits, int $n): string
    {
        $k = strlen($digits);
        return match (true) {
            $k <= $n && $n <= 21 => $digits . str_repeat('0', $n - $k) . '.0',
            0 < $n && $n < $k => substr($digits, 0, $n) . '.' . substr($digits, $n),
            -6 < $n && $n <= 0 => '0.' . str_repeat('0', -$n) . $digits,
            default => $digits[0] . '.' . ($k === 1 ? '0' : substr($digits, 1)) . 'e' . ($n - 1),
        };
    }

    /**
     * A decimal as it was given: its digits with exactly its scale and its
     * sign, negative zero included; a positive exponent written out.
     */
    private static function decimal(DXNDecimal $d): string
    {
        $digits = $d->unscaledValue;
        if ($d->exponent >= 0) {
            $body = $digits . str_repeat('0', $d->exponent);
        } else {
            $scale = -$d->exponent;
            $padded = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            $body = substr($padded, 0, strlen($padded) - $scale) . '.' . substr($padded, strlen($padded) - $scale);
        }
        return ($d->sign < 0 ? '-' : '') . $body . 'M';
    }

    // ---- text ----------------------------------------------------------------

    private static function quoted(string $s): string
    {
        $out = '"';
        foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
            $out .= self::escaped(mb_ord($ch, 'UTF-8'));
        }
        return $out . '"';
    }

    private static function escaped(int $cp): string
    {
        return match (true) {
            $cp === 0x22 => '\\"',
            $cp === 0x5c => '\\\\',
            $cp === 0x0a => '\\n',
            $cp === 0x09 => '\\t',
            $cp === 0x0d => '\\r',
            $cp < 0x20 || $cp === 0x7f => sprintf('\\x{%x}', $cp),
            default => mb_chr($cp, 'UTF-8'),
        };
    }

    private static function charBody(int $cp): string
    {
        return match (true) {
            $cp === 0x20 => 's',
            $cp === 0x5c => '\\\\',
            $cp === 0x0a => '\\n',
            $cp === 0x09 => '\\t',
            $cp === 0x0d => '\\r',
            $cp < 0x20 || $cp === 0x7f || preg_match('/^\s$/u', (string) mb_chr($cp, 'UTF-8')) === 1 => sprintf('\\x{%x}', $cp),
            default => (string) mb_chr($cp, 'UTF-8'),
        };
    }

    // ---- time and the rest -------------------------------------------------

    /** `HH:MM:SS.ffffff` with trailing zeros of the fraction removed, and a zero fraction dropped. */
    private static function trimmed(string $clock): string
    {
        $parts = explode('.', $clock, 2);
        if (count($parts) === 1) {
            return $clock;
        }
        $kept = rtrim($parts[1], '0');
        return $kept === '' ? $parts[0] : "{$parts[0]}.{$kept}";
    }

    /** An instant's text with its clock trimmed, the date and the zone kept. */
    private static function trimmedInstant(string $instant): string
    {
        return (string) preg_replace_callback(
            '/(\d\d:\d\d:\d\d)(\.\d+)?/',
            static fn (array $m): string => self::trimmed($m[1] . ($m[2] ?? '')),
            $instant,
            1,
        );
    }

    /** @param array<string, bool> $flags */
    private static function regexFlags(array $flags): string
    {
        $set = array_keys(array_filter($flags));
        sort($set, SORT_STRING);
        return implode('', $set);
    }
}
