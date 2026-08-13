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
 * Every key reduces to whatever PHP scalar it naturally coerces to (a
 * `DXNKeyword` reduces to its bare name string, matching `map_entry`'s
 * shorthand form exactly; everything else follows PHP's own
 * int/string/bool/float/null array-key coercion). Two *distinct* DXN
 * keys that would coerce to the same PHP slot (`1` and `"1"`; `:name`
 * and `"name"`) is a decode error, not silent data loss -- ordinary
 * duplicate keys (the same key appearing twice) still follow normal
 * last-write-wins map semantics.
 */
final class MapBuilder
{
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
            is_string($dxnKey) => $dxnKey,
            is_int($dxnKey) => $dxnKey,
            is_bool($dxnKey) => $dxnKey ? 1 : 0,
            is_float($dxnKey) => (int) $dxnKey,
            $dxnKey === null => '',
            default => throw $errorFactory(
                'map key of kind "' . Equality::kindOf($dxnKey) . '" has no PHP array-key representation',
            ),
        };
    }

    private static function describeKey(int|string $phpKey): string
    {
        return is_string($phpKey) ? "\"{$phpKey}\"" : (string) $phpKey;
    }
}
