<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

/**
 * This implementation's answers to the Dextrin Conformance Suite.
 *
 * The suite runs every DXN implementation against the same cases,
 * reaching each through its `conformance` command (`dextrin conformance
 * REQUEST ANSWER` here) with a JSON request, and reading a JSON answer:
 *
 *     {"cases":   [{"id": "scalars: an integer", "operation": "decode", "text": "42"}]}
 *     {"results": [{"id": "scalars: an integer", "canonical": "42"}]}
 *
 * `decode` reads `text` and answers its canonical form (DXN.md §1.5);
 * `decode-binary` reads `binary`, a base64 `.dxnb` document, and answers
 * its canonical form; `encode` reads `text` and answers the exact bytes
 * as base64. Any of them may answer `error` instead: the error's
 * category (DXN.md §5), never its message. An error with no category yet
 * is `uncategorised`, which no case expects, so it shows as a
 * disagreement rather than passing by accident.
 */
final class Conformance
{
    private function __construct()
    {
    }

    /**
     * The answer to a decoded JSON request: one result per case, in order.
     *
     * @param array{cases: list<array<string, string>>} $request
     * @return array{results: list<array<string, string>>}
     */
    public static function answer(array $request): array
    {
        return ['results' => array_map(
            static fn (array $case): array => ['id' => $case['id']] + self::outcome($case),
            $request['cases'],
        )];
    }

    /**
     * @param array<string, string> $case
     * @return array<string, string>
     */
    private static function outcome(array $case): array
    {
        try {
            return match ($case['operation'] ?? null) {
                'decode' => ['canonical' => Canonical::print(Dextrin::decode($case['text']))],
                'decode-binary' => self::decodedBinary($case['binary']),
                'encode' => ['binary' => base64_encode(Dextrin::encodeBinary(Dextrin::decode($case['text'])))],
                default => ['error' => 'unsupported operation ' . json_encode($case['operation'] ?? null)],
            };
        } catch (DXNError $err) {
            return ['error' => $err->category ?? 'uncategorised'];
        }
    }

    /** @return array<string, string> */
    private static function decodedBinary(string $base64): array
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            return ['error' => 'binary'];
        }
        return ['canonical' => Canonical::print(Dextrin::decodeBinary($bytes))];
    }
}
