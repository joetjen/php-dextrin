<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\SchemaValidationError;
use JOetjen\Dextrin\Value\DXNBytes;
use PHPUnit\Framework\TestCase;

/**
 * Encoded content, the superset's group 5 (design/schema-superset.md,
 * Jan, 2026-10-20; as dextrin 6918cf8): `encoding:`, `media-type:` and
 * `content:` on strings (JSON Schema's contentEncoding,
 * contentMediaType and contentSchema), `encodes:` and `encodes-seq:` on
 * bytes (CDDL's `.cbor` and `.cborseq`).
 */
final class ContentTest extends TestCase
{
    private static function compiled(string $type): Registry
    {
        return Schema::compile(Dextrin::decode("%{T: {$type}}"), new Registry());
    }

    /** Whether `$value` is a `$type`; only a type mismatch answers false. */
    private static function takes(string $type, mixed $value): bool
    {
        $registry = self::compiled($type);
        try {
            return Schema::check($value, $registry, 'T');
        } catch (SchemaValidationError $err) {
            self::assertSame('schema/type-mismatch', $err->category());
            return false;
        }
    }

    /** The message `$type` is refused with when it compiles, a `schema/compile` error. */
    private static function compileError(string $type): string
    {
        try {
            self::compiled($type);
        } catch (\Throwable $err) {
            $category = match (true) {
                $err instanceof HasCategory => $err->category(),
                $err instanceof DXNError => $err->category,
                default => null,
            };
            self::assertSame('schema/compile', $category);
            return $err->getMessage();
        }
        self::fail("{$type} should be refused");
    }

    /** The value written `$text`, as `.dxnb`. */
    private static function dxnb(string $text): string
    {
        return Dextrin::encodeBinary(Dextrin::decode($text));
    }

    // ---- encoding ------------------------------------------------------------------

    public function testTheStringIsValidInThatRfc4648Encoding(): void
    {
        self::assertTrue(self::takes('{:refine :string %{encoding: :base64}}', 'aGk='));
        self::assertFalse(self::takes('{:refine :string %{encoding: :base64}}', 'aGk'));
        self::assertFalse(self::takes('{:refine :string %{encoding: :base64}}', 'a-k='));
        self::assertTrue(self::takes('{:refine :string %{encoding: :base64url}}', 'a-_9'));
        self::assertTrue(self::takes('{:refine :string %{encoding: :base64url}}', 'aGk'));
        self::assertTrue(self::takes('{:refine :string %{encoding: :base32}}', 'NBUQ===='));
        self::assertFalse(self::takes('{:refine :string %{encoding: :base32}}', 'nbuq===='));
        self::assertTrue(self::takes('{:refine :string %{encoding: :base16}}', '6869'));
        self::assertTrue(self::takes('{:refine :string %{encoding: :base16}}', '6A6b'));
        self::assertFalse(self::takes('{:refine :string %{encoding: :base16}}', '686'));
    }

    public function testAnUnknownEncodingIsACompileError(): void
    {
        self::assertStringContainsString('encoding', self::compileError('{:refine :string %{encoding: :rot13}}'));
    }

    // ---- media-type ----------------------------------------------------------------

    public function testMediaTypeSaysWhatTheStringHoldsAndChecksNothingElse(): void
    {
        self::assertTrue(self::takes('{:refine :string %{media-type: "image/png"}}', 'anything'));
        self::assertFalse(self::takes('{:refine :string %{media-type: "image/png"}}', 5));
    }

    public function testMediaTypeIsATypeSubtypeName(): void
    {
        self::assertStringContainsString('media-type', self::compileError('{:refine :string %{media-type: "png"}}'));
    }

    // ---- content -------------------------------------------------------------------

    public function testTheStringIsADxnDocumentWhoseValueIsAT(): void
    {
        $type = '{:refine :string %{content: {:list-of :integer}}}';
        self::assertTrue(self::takes($type, '[1 2 3]'));
        self::assertFalse(self::takes($type, '["a"]'));
        self::assertFalse(self::takes($type, '[1 2'));
    }

    public function testWithAnEncodingTheDecodedBytesAreTheDocumentDxnbUnderApplicationDxnb(): void
    {
        $type = '{:refine :string %{encoding: :base64, media-type: "application/dxnb", content: :integer}}';
        self::assertTrue(self::takes($type, base64_encode(self::dxnb('42'))));
        self::assertFalse(self::takes($type, base64_encode(self::dxnb('"x"'))));

        $text = '{:refine :string %{encoding: :base16, content: :integer}}';
        self::assertTrue(self::takes($text, strtoupper(bin2hex('42'))));
    }

    public function testAnyOtherMediaTypeHasNoReaderAndIsACompileError(): void
    {
        self::assertStringContainsString(
            'content',
            self::compileError('{:refine :string %{media-type: "application/json", content: :any}}'),
        );
    }

    // ---- encodes -------------------------------------------------------------------

    public function testTheBytesAreADxnbDocumentWhoseValueIsAT(): void
    {
        self::assertTrue(self::takes('{:refine :bytes %{encodes: {:list-of :integer}}}', new DXNBytes(self::dxnb('[1 2]'))));
        self::assertFalse(self::takes('{:refine :bytes %{encodes: :string}}', new DXNBytes(self::dxnb('[1 2]'))));
        self::assertFalse(self::takes('{:refine :bytes %{encodes: :any}}', new DXNBytes('not dxnb')));
    }

    public function testEncodesSeqDocumentsBackToBackEachATNoneAtAllToo(): void
    {
        $seq = new DXNBytes(self::dxnb('1') . self::dxnb('2') . self::dxnb('3'));
        self::assertTrue(self::takes('{:refine :bytes %{encodes-seq: :integer}}', $seq));
        self::assertFalse(self::takes('{:refine :bytes %{encodes-seq: :string}}', $seq));
        self::assertTrue(self::takes('{:refine :bytes %{encodes-seq: :integer}}', new DXNBytes('')));
        self::assertFalse(self::takes('{:refine :bytes %{encodes-seq: :integer}}', new DXNBytes(self::dxnb('1') . 'DX')));
    }

    public function testACheckInsideADecodeLeavesTheDecodesOwnStateAlone(): void
    {
        $registry = Schema::compile(
            Dextrin::decode('%{Box: %schema{payload: {:refine :bytes %{encodes: :string}}, again: :string}}'),
            new Registry(),
        );
        $inner = base64_encode(self::dxnb('"shared"'));

        $text = "[%Box{payload: @bytes \"{$inner}\", again: \"shared\"} \"shared\"]";
        $value = Dextrin::decode($text, $registry);
        self::assertSame('shared', self::second($value));

        $bytes = Dextrin::encodeBinary($value, $registry);
        self::assertSame('shared', self::second(Dextrin::decodeBinary($bytes, $registry)));
    }

    /** A two-item list's second item. */
    private static function second(mixed $list): mixed
    {
        $items = is_array($list) ? $list : ($list->items ?? null);
        self::assertIsArray($items);
        self::assertCount(2, $items);
        return $items[1];
    }
}
