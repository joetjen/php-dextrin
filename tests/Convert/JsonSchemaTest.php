<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Convert;

use JOetjen\Dextrin\Convert\Json;
use JOetjen\Dextrin\Convert\JsonObject;
use JOetjen\Dextrin\Convert\JsonSchema;
use JOetjen\Dextrin\Convert\JsonSchemaError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNMap;
use PHPUnit\Framework\TestCase;

/**
 * The JSON Schema converter's own choices (T674 of dextrin's
 * design/json-schema.md): what the official suite does not decide --
 * names, shapes or structs, logical types, MongoDB's bsonType, what is
 * kept in meta, and what is reported. As the reference's
 * json_schema_test.exs, with PHP's own JSON reading added.
 */
final class JsonSchemaTest extends TestCase
{
    /**
     * @param array{name?: ?string, draft?: ?string, mongo?: bool, structs?: bool, assertFormats?: bool} $options
     * @return array{0: string, 1: array<string, mixed>, 2: list<array{path: string, why: string}>}
     */
    private static function dxns(string $json, array $options = []): array
    {
        ['document' => $doc, 'inexact' => $inexact] = JsonSchema::toDxns(['schema.json', $json], $options);
        return [Dextrin::encode($doc, pretty: true), $doc, $inexact];
    }

    /** @param array<string, mixed> $doc */
    private static function registry(array $doc): Registry
    {
        return Schema::compile($doc, new Registry());
    }

    private static function takes(Registry $registry, string $name, string $json): bool
    {
        try {
            Schema::check(JsonSchema::jsonValue(JsonSchema::decodeJson($json)), $registry, $name);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function refusal(callable $run): string
    {
        try {
            $run();
        } catch (JsonSchemaError $err) {
            return $err->getMessage();
        }
        self::fail('expected a refusal');
    }

    public function testAnObjectIsAShapeItsFieldsInOrderThoseNotRequiredOptional(): void
    {
        [$text, , $inexact] = self::dxns('{"title": "Order", "type": "object", "required": ["id"], "properties": {"id": {"type": "integer"}, "note": {"type": "string"}}}');
        self::assertSame([], $inexact);
        self::assertStringContainsString('Order: {:doc %shape{id: :integer note?: :string} %{title: "Order"}}', $text);
    }

    public function testDefsAreNamedEntriesAndARefIsTheName(): void
    {
        [$text, $doc, $inexact] = self::dxns('{"$defs": {"Money": {"type": "number", "minimum": 0}}, "type": "object", "properties": {"price": {"$ref": "#/$defs/Money"}}}');
        self::assertSame([], $inexact);
        self::assertStringContainsString('Money: {:refine :number %{min: 0}}', $text);
        self::assertStringContainsString('price?: Money', $text);
        self::assertTrue(self::takes(self::registry($doc), 'Root', '{"price": 2}'));
        self::assertFalse(self::takes(self::registry($doc), 'Root', '{"price": -1}'));
    }

    public function testStructsWritesANamedObjectAsASchema(): void
    {
        [$text, , $inexact] = self::dxns(
            '{"$defs": {"Pet": {"type": "object", "properties": {"name": {"type": "string"}}}}, "$ref": "#/$defs/Pet"}',
            ['structs' => true],
        );
        self::assertSame([], $inexact);
        self::assertStringContainsString('Pet: %schema{name?: :string}', $text);
    }

    public function testADateTimeStringIsADatetimeWhereFormatsAreCheckedAStringWithMetaWhereNot(): void
    {
        $schema = '{"type": "string", "format": "date-time"}';
        [$text] = self::dxns('{"$schema": "http://json-schema.org/draft-07/schema#", "type": "string", "format": "date-time"}');
        self::assertStringContainsString('Root: :datetime', $text);
        [$text] = self::dxns($schema);
        self::assertStringContainsString(':string', $text);
        self::assertStringContainsString('"json-schema.format" => "date-time"', $text);
        [$text] = self::dxns($schema, ['assertFormats' => true]);
        self::assertStringContainsString('Root: :datetime', $text);
    }

    public function testAnUnknownFormatIsKeptInMetaAndChecksNothing(): void
    {
        [$text, $doc, $inexact] = self::dxns('{"type": "string", "format": "postal-code"}', ['assertFormats' => true]);
        self::assertSame([], $inexact);
        self::assertStringContainsString('"json-schema.format" => "postal-code"', $text);
        self::assertTrue(self::takes(self::registry($doc), 'Root', '"anything"'));
    }

    public function testPatternPropertiesAndAdditionalPropertiesFalseAreEntriesAndClosed(): void
    {
        [$text, $doc, $inexact] = self::dxns('{"type": "object", "properties": {"id": {"type": "integer"}}, "patternProperties": {"^x-": {"type": "string"}}, "additionalProperties": false}');
        self::assertSame([], $inexact);
        self::assertStringContainsString('- *', $text);
        self::assertStringContainsString('* {:refine :string %{pattern:', $text);
        $r = self::registry($doc);
        self::assertTrue(self::takes($r, 'Root', '{"id": 1, "x-a": "s"}'));
        self::assertFalse(self::takes($r, 'Root', '{"id": 1, "x-a": 2}'));
        self::assertFalse(self::takes($r, 'Root', '{"id": 1, "y": 2}'));
    }

    public function testARequiredPropertyThatIsNoIdentifierIsStillRequired(): void
    {
        [, $doc, $inexact] = self::dxns('{"type": "object", "required": ["a b"]}');
        self::assertSame([], $inexact);
        $r = self::registry($doc);
        self::assertTrue(self::takes($r, 'Root', '{"a b": 1}'));
        self::assertFalse(self::takes($r, 'Root', '{"a": 1}'));
    }

    public function testMongoBsonTypeMapsToDxnsOwnTypes(): void
    {
        [$text, , $inexact] = self::dxns('{"bsonType": "object", "required": ["_id"], "properties": {"_id": {"bsonType": "objectId"}, "at": {"bsonType": "date"}, "n": {"bsonType": "long"}, "price": {"bsonType": "decimal"}}}');
        self::assertSame([], $inexact);
        self::assertStringContainsString('_id: :objectid', $text);
        self::assertStringContainsString('at?: {:refine :timestamp %{resolution: :millisecond}}', $text);
        self::assertStringContainsString('n?: :int64', $text);
        self::assertStringContainsString('price?: {:refine :decimal %{max-precision: 34}}', $text);
    }

    public function testMongoABsonTypeWithNoDxnValueIsAnyAndReported(): void
    {
        [, , $inexact] = self::dxns('{"bsonType": "javascript"}');
        self::assertCount(1, $inexact);
        self::assertStringContainsString('javascript', $inexact[0]['why']);
    }

    public function testARefToADocumentNotGivenIsAnErrorNothingIsFetched(): void
    {
        $why = self::refusal(static fn () => JsonSchema::toDxns(['a.json', '{"$ref": "https://example.com/b.json"}']));
        self::assertStringContainsString('names no schema among those given', $why);
    }

    public function testARefToAnotherFileGivenResolves(): void
    {
        ['document' => $doc] = JsonSchema::toDxns([
            ['a.json', '{"type": "object", "properties": {"b": {"$ref": "b.json"}}}'],
            ['b.json', '{"title": "B", "type": "integer"}'],
        ]);
        self::assertTrue(self::takes(self::registry($doc), 'Root', '{"b": 1}'));
        self::assertFalse(self::takes(self::registry($doc), 'Root', '{"b": "x"}'));
    }

    public function testSomethingNotASchemaIsRefused(): void
    {
        self::assertStringContainsString('object or a boolean', self::refusal(static fn () => JsonSchema::toDxns(['a.json', '12'])));
    }

    public function testAnEcma262PatternReadsAsJsonSchemaMeansIt(): void
    {
        [, $doc, $inexact] = self::dxns('{"type": "string", "pattern": "^\\\\d+$"}');
        self::assertSame([], $inexact);
        $r = self::registry($doc);
        self::assertTrue(self::takes($r, 'Root', '"123"'));
        self::assertFalse(self::takes($r, 'Root', '"123\\n"'));
        self::assertFalse(self::takes($r, 'Root', '"١٢٣"'));
    }

    public function testAPatternReadsCodePointsAsEcma262WithUDoes(): void
    {
        [, $doc, $inexact] = self::dxns('{"type": "string", "pattern": "^.$"}');
        self::assertSame([], $inexact);
        self::assertTrue(self::takes(self::registry($doc), 'Root', '"é"'));
        [, $doc, $inexact] = self::dxns('{"type": "string", "pattern": "^\\u0100$"}');
        self::assertSame([], $inexact);
        self::assertTrue(self::takes(self::registry($doc), 'Root', '"Ā"'));
        self::assertFalse(self::takes(self::registry($doc), 'Root', '"A"'));
    }

    public function testAKeywordSetToNullSaysNothing(): void
    {
        [, $doc] = self::dxns('{"type": "number", "minimum": null}');
        self::assertTrue(self::takes(self::registry($doc), 'Root', '-5'));
    }

    public function testAnOldStyleDependenciesSchemaIsConvertedWhereItStands(): void
    {
        [$text] = self::dxns('{"dependencies": {"bar": {"properties": {"foo": {"type": "integer"}}}}, "properties": {"x": {"$ref": "#/dependencies/bar"}}}', ['draft' => '07']);
        self::assertStringContainsString('{:if %shape{bar: :any} RootDependenciesBar}', $text);
    }

    public function testAContainsReachedThroughUnevaluatedItemsIsReportedAtItsOwnPath(): void
    {
        $contains = '{"type": "string", "pattern": "[\\\\D]"}';
        [, , $inexact] = self::dxns('{"properties": {"a": {"contains": ' . $contains . ', "unevaluatedItems": false}}}');
        self::assertSame(['#/properties/a/contains/pattern', '#/properties/a/contains/pattern'], array_column($inexact, 'path'));
        [, , $inexact] = self::dxns('{"allOf": [{"contains": ' . $contains . '}], "unevaluatedItems": false}');
        self::assertSame(['#/allOf/0/contains/pattern', '#/allOf/0/contains/pattern'], array_column($inexact, 'path'));
    }

    // ---- reading JSON in PHP ----------------------------------------------------------------

    public function testJsonKeepsObjectKeyOrderAndTellsAnEmptyObjectFromAnEmptyArray(): void
    {
        $value = JsonSchema::decodeJson('{"b": 1, "a": {}, "1": [], "": null}');
        self::assertInstanceOf(JsonObject::class, $value);
        self::assertSame(['b', 'a', '1', ''], $value->keys());
        self::assertInstanceOf(JsonObject::class, $value->get('a'));
        self::assertSame([], $value->get('1'));
        self::assertInstanceOf(DXNMap::class, JsonSchema::jsonValue($value->get('a')));
        self::assertInstanceOf(DXNList::class, JsonSchema::jsonValue($value->get('1')));
    }

    public function testJsonKeeps10AFloatAndAnIntegerPast64BitsAnInteger(): void
    {
        self::assertSame(1.0, JsonSchema::decodeJson('1.0'));
        self::assertSame(1, JsonSchema::decodeJson('1'));
        self::assertSame(100.0, JsonSchema::decodeJson('1e2'));
        self::assertEquals(new DXNInteger('18446744073709551616'), JsonSchema::decodeJson('18446744073709551616'));
        self::assertSame("\u{e9}\u{1F600}", JsonSchema::decodeJson('"\\u00e9\\ud83d\\ude00"'));
    }

    public function testJsonRefusesWhatIsNotJson(): void
    {
        foreach (['{"a": 1,}', '[1 2]', '01', '"a', '1 2', "{'a': 1}", 'nul'] as $text) {
            try {
                Json::decode($text);
                self::fail("{$text} should be refused");
            } catch (JsonSchemaError) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAKeyWrittenTwiceKeepsItsFirstPlaceAndItsLastValue(): void
    {
        $value = JsonSchema::decodeJson('{"a": 1, "b": 2, "a": 3}');
        self::assertInstanceOf(JsonObject::class, $value);
        self::assertSame([['a', 3], ['b', 2]], $value->pairs());
    }

    // ---- bin/dextrin from-json-schema ---------------------------------------------------------

    /** @return array{int, string, string} exit status, standard output, standard error */
    private static function command(string ...$args): array
    {
        $process = proc_open(
            [PHP_BINARY, 'bin/dextrin', 'from-json-schema', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    private static function tmp(): string
    {
        $dir = sys_get_temp_dir() . '/dextrin-json-schema-' . bin2hex(random_bytes(6));
        mkdir($dir);
        return $dir;
    }

    public function testTheCommandWritesTheDxnsAndReportsWhatIsNotExact(): void
    {
        $dir = self::tmp();
        file_put_contents("{$dir}/s.json", '{"title": "Thing", "bsonType": "javascript"}');
        [$status, , $err] = self::command("{$dir}/s.json", '--out', "{$dir}/s.dxns");
        self::assertSame(0, $status, $err);
        self::assertStringContainsString('inexact: #: bsonType javascript', $err);
        self::assertStringContainsString('Thing: {:doc :any %{title: "Thing"}}', (string) file_get_contents("{$dir}/s.dxns"));
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testTheCommandWithStrictFailsOnAnythingNotExact(): void
    {
        $dir = self::tmp();
        file_put_contents("{$dir}/s.json", '{"bsonType": "javascript"}');
        [$status, , $err] = self::command("{$dir}/s.json", '--strict');
        self::assertSame(1, $status);
        self::assertStringContainsString('not exact', $err);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testTheCommandReadsTheOtherFilesUnderSchemasAndTakesTheOptions(): void
    {
        $dir = self::tmp();
        mkdir("{$dir}/more");
        file_put_contents("{$dir}/a.json", '{"type": "object", "properties": {"b": {"$ref": "more/b.json"}}}');
        file_put_contents("{$dir}/more/b.json", '{"title": "B", "type": "object", "properties": {"n": {"type": "integer"}}}');
        [$status, $out, $err] = self::command("{$dir}/a.json", '--schemas', $dir, '--name', 'Api', '--structs', '--draft', '07');
        self::assertSame(0, $status, $err);
        self::assertStringContainsString('Api: %schema{b?: B}', $out);
        self::assertStringContainsString('B: {:doc %schema{n?: :integer} %{title: "B"}}', $out);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
