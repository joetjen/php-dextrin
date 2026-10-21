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
 * as base64. A case may carry `schemas`, `.dxns` sources compiled in
 * order into the registry it runs with, and `strict: true`, which
 * decodes strictly (DXNS.md §7). `format` reads `text` as a document and
 * answers it laid out, comments kept, as `text`; `edit` reads `text` as a
 * document, makes its `edits` in order -- `replace`, `delete`, `insert`,
 * `update`, their `path`, `value` and `where` written as DXN text -- and
 * answers the document rendered, as `text`; a refused edit answers
 * `document/<reason>`. `media-type` answers the form `media-type` names
 * (DXN.md §6) as `form` -- `text`, `binary`, `schema` or `none`;
 * `identify` whether `text` is worth decoding as its `form`, `text` or
 * `schema`, and `identify-binary` whether `binary`, base64, is a `.dxnb`
 * document, each as `identified`: `true` or `false`. Any of them may
 * answer `error` instead: the error's
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
     * @param array{cases: list<array<string, mixed>>} $request
     * @return array{results: list<array<string, string>>}
     */
    public static function answer(array $request): array
    {
        return ['results' => array_map(
            static fn (array $case): array => ['id' => $case['id']] + self::outcome($case),
            $request['cases'],
        )];
    }

    /** Whether a body was identified, as the suite reads it. */
    private static function truth(bool $yes): string
    {
        return $yes ? 'true' : 'false';
    }

    /**
     * Whether `$base64` is a `.dxnb` document, or `binary` when it is not
     * base64 at all.
     *
     * @return array<string, string>
     */
    private static function identifiedBinary(string $base64): array
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            return ['error' => 'binary'];
        }
        return ['identified' => self::truth(Format::identifies($bytes, Form::Binary))];
    }

    /**
     * @param array<string, mixed> $case
     * @return array<string, string>
     */
    private static function outcome(array $case): array
    {
        try {
            $registry = self::loaded($case['schemas'] ?? []);
            $strict = ($case['strict'] ?? false) === true;
            return match ($case['operation'] ?? null) {
                'decode' => ['canonical' => Canonical::print(Dextrin::decode(self::text($case, 'text'), $registry, $strict))],
                'decode-binary' => self::decodedBinary(self::text($case, 'binary'), $registry, $strict),
                'format' => ['text' => Document::parse(self::text($case, 'text'), $registry, $strict)->format()],
                'edit' => ['text' => self::edited(
                    Document::parse(self::text($case, 'text'), $registry, $strict),
                    $case['edits'] ?? [],
                    $registry,
                )->render()],
                'encode' => ['binary' => base64_encode(Dextrin::encodeBinary(
                    Dextrin::decode(self::text($case, 'text'), $registry, $strict),
                    registry: $registry,
                ))],
                'media-type' => ['form' => Format::form(self::text($case, 'media-type'))->value ?? 'none'],
                'identify' => ['identified' => self::truth(Format::identifies(
                    self::text($case, 'text'),
                    ($case['form'] ?? null) === 'schema' ? Form::Schema : Form::Text,
                ))],
                'identify-binary' => self::identifiedBinary(self::text($case, 'binary')),
                default => ['error' => 'unsupported operation ' . json_encode($case['operation'] ?? null)],
            };
        } catch (DXNError $err) {
            return ['error' => $err->category ?? 'uncategorised'];
        } catch (Document\DocumentError $err) {
            return ['error' => $err->category()];
        }
    }

    /**
     * `$doc` after `$edits`, made in order; each one's path, value and
     * where are DXN text, read by this implementation's own decoder.
     */
    private static function edited(Document $doc, mixed $edits, Registry $registry): Document
    {
        foreach (is_array($edits) ? $edits : [] as $edit) {
            $read = static fn (string $key): mixed => Dextrin::decode(is_array($edit) && is_string($edit[$key] ?? null) ? $edit[$key] : 'nil', $registry);
            $path = static function () use ($read): array {
                $list = $read('path');
                return $list instanceof Value\DXNList ? $list->items : [];
            };
            $doc = match (is_array($edit) ? ($edit['op'] ?? null) : null) {
                'replace' => $doc->replace($path(), $read('value')),
                'delete' => $doc->delete($path()),
                'insert' => $doc->insert($path(), $read('where'), $read('value')),
                'update' => $doc->update($read('value'), is_string($edit['on_ambiguity'] ?? null) ? $edit['on_ambiguity'] : 'safe')[0],
                default => throw new \InvalidArgumentException('unknown edit ' . json_encode($edit)),
            };
        }
        return $doc;
    }

    /**
     * A case's schemas, compiled in order into one registry.
     *
     * @param mixed $sources
     */
    private static function loaded(mixed $sources): Registry
    {
        $registry = new Registry();
        foreach (is_array($sources) ? $sources : [] as $source) {
            try {
                $registry = Schema::compile(Dextrin::decode((string) $source), $registry);
            } catch (\Throwable $err) {
                throw DXNError::wrap($err, 'schema');
            }
        }
        return $registry;
    }

    /**
     * One of a case's text fields.
     *
     * @param array<string, mixed> $case
     */
    private static function text(array $case, string $key): string
    {
        $value = $case[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /** @return array<string, string> */
    private static function decodedBinary(string $base64, Registry $registry, bool $strict): array
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            return ['error' => 'binary'];
        }
        return ['canonical' => Canonical::print(Dextrin::decodeBinary($bytes, $registry, $strict))];
    }
}
