<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests;

use JOetjen\Dextrin\Conformance;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\DXNError;
use PHPUnit\Framework\TestCase;

/**
 * This implementation's adapter for the Dextrin Conformance Suite, and
 * the error categories (DXN.md §5) it answers errors with.
 */
final class ConformanceTest extends TestCase
{
    /**
     * @param list<array<string, string>> $cases
     * @return list<array<string, string>>
     */
    private static function answered(array $cases): array
    {
        return Conformance::answer(['cases' => $cases])['results'];
    }

    private static function categoryOf(\Closure $failing): ?string
    {
        try {
            $failing();
        } catch (DXNError $err) {
            return $err->category;
        }
        self::fail('expected a DXNError');
    }

    public function testTextThatDoesNotParseIsASyntaxError(): void
    {
        self::assertSame('syntax', self::categoryOf(static fn () => Dextrin::decode('[1 2')));
    }

    public function testBytesWithoutTheMagicAreABinaryError(): void
    {
        self::assertSame('binary', self::categoryOf(static fn () => Dextrin::decodeBinary("\x18\x2a")));
    }

    public function testAValueWithNoDxnMeaningIsAnEncodeError(): void
    {
        self::assertSame('encode/unsupported', self::categoryOf(static fn () => Dextrin::encode(static fn () => 1)));
    }

    public function testAMediaTypeAnswersTheFormItNamesOrNone(): void
    {
        self::assertSame(
            [['id' => 'a', 'form' => 'text'], ['id' => 'b', 'form' => 'none']],
            self::answered([
                ['id' => 'a', 'operation' => 'media-type', 'media-type' => 'application/vnd.acme.x+dxn'],
                ['id' => 'b', 'operation' => 'media-type', 'media-type' => 'application/json'],
            ]),
        );
    }

    public function testABodyAnswersWhetherItIsWorthDecodingAsItsForm(): void
    {
        self::assertSame(
            [['id' => 'a', 'identified' => 'true'], ['id' => 'b', 'identified' => 'false'], ['id' => 'c', 'identified' => 'true']],
            self::answered([
                ['id' => 'a', 'operation' => 'identify', 'form' => 'schema', 'text' => "# s\n%{}"],
                ['id' => 'b', 'operation' => 'identify', 'form' => 'text', 'text' => '[1]'],
                ['id' => 'c', 'operation' => 'identify-binary', 'binary' => 'RFgB'],
            ]),
        );
    }

    public function testADecodeAnswersTheCanonicalForm(): void
    {
        self::assertSame(
            [['id' => 'a', 'canonical' => '%{a: 1 b: 2}']],
            self::answered([['id' => 'a', 'operation' => 'decode', 'text' => '%{b: 2, a: 1}']]),
        );
    }

    public function testTextThatDoesNotParseAnswersItsCategory(): void
    {
        self::assertSame(
            [['id' => 'a', 'error' => 'syntax']],
            self::answered([['id' => 'a', 'operation' => 'decode', 'text' => '[1 2']]),
        );
    }

    public function testABinaryDecodeReadsBase64(): void
    {
        self::assertSame(
            [['id' => 'a', 'canonical' => '42']],
            self::answered([['id' => 'a', 'operation' => 'decode-binary', 'binary' => 'RFgBGCo=']]),
        );
    }

    public function testMalformedBytesAnswerTheirCategory(): void
    {
        self::assertSame(
            [['id' => 'a', 'error' => 'binary']],
            self::answered([['id' => 'a', 'operation' => 'decode-binary', 'binary' => 'GCo=']]),
        );
    }

    public function testAnEncodeAnswersTheExactBytesADecimalKeepingItsScale(): void
    {
        self::assertSame(
            [['id' => 'a', 'binary' => 'RFgBGCo='], ['id' => 'b', 'binary' => 'RFgBxIIhGJY=']],
            self::answered([
                ['id' => 'a', 'operation' => 'encode', 'text' => '42'],
                ['id' => 'b', 'operation' => 'encode', 'text' => '1.50M'],
            ]),
        );
    }

    private const USER = '%{User: %schema(2){name: :string}}';

    public function testACasesSchemasAreLoadedBeforeItRuns(): void
    {
        self::assertSame(
            [['id' => 'a', 'canonical' => '%User(2){name: "x"}']],
            self::answered([['id' => 'a', 'operation' => 'decode', 'text' => '%User{name: "x"}', 'schemas' => [self::USER]]]),
        );
    }

    public function testAndASchemaViolationAnswersItsCategory(): void
    {
        self::assertSame(
            [['id' => 'a', 'error' => 'schema/type-mismatch']],
            self::answered([['id' => 'a', 'operation' => 'decode', 'text' => '%User{name: 1}', 'schemas' => [self::USER]]]),
        );
    }

    public function testAStrictCaseDecodesStrictly(): void
    {
        self::assertSame(
            [['id' => 'a', 'error' => 'schema/undeclared-field']],
            self::answered([[
                'id' => 'a', 'operation' => 'decode', 'text' => '%User{name: "x", nick: "y"}',
                'schemas' => [self::USER], 'strict' => true,
            ]]),
        );
    }

    public function testAStructDecodedAgainstASchemaIsEncodedUnderItsVersionedTypeName(): void
    {
        self::assertSame(
            [['id' => 'a', 'binary' => base64_encode(hex2bin('445801d8d08267557365722832296178'))]],
            self::answered([['id' => 'a', 'operation' => 'encode', 'text' => '%User{name: "x"}', 'schemas' => [self::USER]]]),
        );
    }

    public function testAFormatAnswersTheDocumentLaidOutItsCommentsKept(): void
    {
        self::assertSame(
            [['id' => 'a', 'text' => "[\n  1  # one\n  2\n]"]],
            self::answered([['id' => 'a', 'operation' => 'format', 'text' => "[1 # one\n 2]"]]),
        );
    }

    public function testAnEditAnswersTheDocumentRenderedAfterItsEditsInOrder(): void
    {
        $edits = [
            ['op' => 'replace', 'path' => '[:name]', 'value' => '"other"'],
            ['op' => 'delete', 'path' => '[:deps 0]'],
            ['op' => 'insert', 'path' => '[:deps]', 'where' => '-1', 'value' => '"c"'],
        ];
        self::assertSame(
            [['id' => 'a', 'text' => "%{\n  name: \"other\" # the name\n  deps: [\"b\" \"c\"]\n}\n"]],
            self::answered([['id' => 'a', 'operation' => 'edit', 'text' => "%{\n  name: \"acme\" # the name\n  deps: [\"a\" \"b\"]\n}\n", 'edits' => $edits]]),
        );
    }

    public function testARefusedEditAnswersDocumentReason(): void
    {
        self::assertSame(
            [['id' => 'a', 'error' => 'document/not-found']],
            self::answered([['id' => 'a', 'operation' => 'edit', 'text' => '%{a: 1}', 'edits' => [['op' => 'delete', 'path' => '[:nope]']]]]),
        );
    }

    public function testASchemaThatDoesNotCompileAnswersItsCategory(): void
    {
        self::assertSame(
            [['id' => 'a', 'error' => 'schema/compile']],
            self::answered([['id' => 'a', 'operation' => 'decode', 'text' => '1', 'schemas' => ['%{User: 1}']]]),
        );
    }

    public function testTheCommandReadsTheRequestAndWritesTheAnswer(): void
    {
        $dir = sys_get_temp_dir() . '/dextrin-conformance-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $request = "{$dir}/request.json";
        $answer = "{$dir}/answer.json";
        file_put_contents($request, json_encode(['cases' => [['id' => 'a', 'operation' => 'decode', 'text' => '42']]]));

        exec(escapeshellarg(PHP_BINARY) . ' bin/dextrin conformance ' . escapeshellarg($request) . ' ' . escapeshellarg($answer), $out, $status);

        self::assertSame(0, $status, implode("\n", $out));
        self::assertSame(['results' => [['id' => 'a', 'canonical' => '42']]], json_decode((string) file_get_contents($answer), true));
    }
}
