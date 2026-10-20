<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Binary;

use JOetjen\Dextrin\Binary\BinaryError;
use JOetjen\Dextrin\Binary\Decoder;
use JOetjen\Dextrin\Binary\Tags;
use JOetjen\Dextrin\Binary\Writer;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\Equality;
use PHPUnit\Framework\TestCase;

final class DecoderTest extends TestCase
{
    private function envelope(string $item): string
    {
        return "DX\x01" . $item;
    }

    public function testRejectsMissingEnvelope(): void
    {
        $this->expectException(BinaryError::class);
        Decoder::decode('nope');
    }

    public function testRejectsWrongVersionByte(): void
    {
        $this->expectException(BinaryError::class);
        Decoder::decode("DX\x02" . "\xf6");
    }

    public function testRejectsTruncatedInput(): void
    {
        $this->expectException(BinaryError::class);
        Decoder::decode('DX');
    }

    public function testRejectsTrailingBytes(): void
    {
        $this->expectException(BinaryError::class);
        Decoder::decode($this->envelope("\xf6\xf6"));
    }

    public function testDecodesScalars(): void
    {
        self::assertNull(Decoder::decode($this->envelope("\xf6")));
        self::assertFalse(Decoder::decode($this->envelope("\xf4")));
        self::assertTrue(Decoder::decode($this->envelope("\xf5")));
        self::assertSame(42, Decoder::decode($this->envelope("\x18\x2a")));
        self::assertSame('"', Decoder::decode($this->envelope("\x61\x22")));
    }

    // Every width CBOR has; the encoder writes the shortest exact one
    // (DXN.md §2.2, Jan, 2026-10-20).
    public function testDecodesHalfPrecisionFloat(): void
    {
        self::assertSame(1.0, Decoder::decode($this->envelope("\xf9\x3c\x00")));
        self::assertSame(-2.0, Decoder::decode($this->envelope("\xf9\xc0\x00")));
    }

    public function testDecodesFloat32AndFloat64(): void
    {
        self::assertSame(1.0, Decoder::decode($this->envelope("\xfa\x3f\x80\x00\x00")));
        self::assertSame(1.0, Decoder::decode($this->envelope("\xfb\x3f\xf0\x00\x00\x00\x00\x00\x00")));
    }

    public function testDecodesNanAndInfinityNatively(): void
    {
        self::assertNan(Decoder::decode($this->envelope("\xfb\x7f\xf8\x00\x00\x00\x00\x00\x00")));
        self::assertSame(INF, Decoder::decode($this->envelope("\xfb\x7f\xf0\x00\x00\x00\x00\x00\x00")));
    }

    public function testDecodesUntaggedListAndMap(): void
    {
        self::assertEquals(new DXNList([1, 2]), Decoder::decode($this->envelope("\x82\x01\x02")));
        // The map key here is an untagged CBOR text item ("a"), a
        // genuine string -- not tag T_KEYWORD -- so it decodes marked
        // with MapBuilder's own leading NUL byte, distinguishing it
        // from a same-text keyword key (see MapBuilder's own doc).
        self::assertSame(["\0a" => 1], Decoder::decode($this->envelope("\xa1\x61\x61\x01")));
    }

    public function testRejectsUnrecognizedTag(): void
    {
        $bytes = $this->envelope(Writer::tag(999, Writer::integerItem(1)));
        $this->expectException(BinaryError::class);
        Decoder::decode($bytes);
    }

    public function testMalformedPayloadShapeThrowsCleanly(): void
    {
        // T_UUID with a non-bytes payload.
        $bytes = $this->envelope(Writer::tag(Tags::T_UUID, Writer::integerItem(1)));
        $this->expectException(BinaryError::class);
        Decoder::decode($bytes);
    }

    public function testUuidPayloadMustBeExactly16Bytes(): void
    {
        $bytes = $this->envelope(Writer::tag(Tags::T_UUID, Writer::bytesItem('short')));
        $this->expectException(BinaryError::class);
        Decoder::decode($bytes);
    }

    public function testCharPayloadRejectsSurrogateRange(): void
    {
        $bytes = $this->envelope(Writer::tag(Tags::T_CHAR, Writer::integerItem(0xD800)));
        $this->expectException(BinaryError::class);
        Decoder::decode($bytes);
    }

    public function testDurationBitmaskMismatchThrows(): void
    {
        // bitmask claims 2 fields present, but only 1 item follows.
        $bytes = $this->envelope(Writer::tag(Tags::T_DURATION, Writer::arrayOf([
            Writer::integerItem(0b11),
            Writer::integerItem(1),
        ])));
        $this->expectException(BinaryError::class);
        Decoder::decode($bytes);
    }

    public function testOrderedMapPayloadMustBeACborMap(): void
    {
        // T_ORDERED wrapping a list (major4), not a map (major5).
        $bytes = $this->envelope(Writer::tag(Tags::T_ORDERED, Writer::arrayOf([Writer::integerItem(1)])));
        $this->expectException(BinaryError::class);
        Decoder::decode($bytes);
    }

    public function testOrderedMapBypassesMapKeyCoercion(): void
    {
        // Keys 1 (int) and "1" (string) would collide under MapBuilder's
        // rules for a plain `map` -- but ordered-map's raw pairs never
        // go through that coercion, so both must survive intact.
        $pairs = Writer::mapOf([
            [Writer::integerItem(1), Writer::textItem('int-keyed')],
            [Writer::textItem('1'), Writer::textItem('string-keyed')],
        ]);
        $bytes = $this->envelope(Writer::tag(Tags::T_ORDERED, $pairs));
        $decoded = Decoder::decode($bytes);
        self::assertInstanceOf(DXNOrderedMap::class, $decoded);
        self::assertCount(2, $decoded->pairs);
        self::assertSame(1, $decoded->pairs[0][0]);
        self::assertSame('1', $decoded->pairs[1][0]);
    }

    public function testUntaggedMapKeepsKeysANativeArrayWouldCollide(): void
    {
        // `1` and `"1"` are two DXN keys a native array would coerce to one
        // slot, so the map decodes to a DXNMap holding both (DXN.md §1.6).
        $pairs = Writer::mapOf([
            [Writer::integerItem(1), Writer::textItem('a')],
            [Writer::textItem('1'), Writer::textItem('b')],
        ]);
        $map = Decoder::decode($this->envelope($pairs));
        self::assertInstanceOf(\JOetjen\Dextrin\Value\DXNMap::class, $map);
        self::assertSame('a', $map->get(1));
        self::assertSame('b', $map->get('1'));
    }

    public function testDecodesBignumTags(): void
    {
        $huge = DXNInteger::fromString('99999999999999999999999999999');
        $bytes = $this->envelope(Writer::integerItem($huge));
        $decoded = Decoder::decode($bytes);
        self::assertTrue(Equality::dxnEquals($huge, $decoded));

        $hugeNeg = DXNInteger::fromString('-99999999999999999999999999999');
        $bytesNeg = $this->envelope(Writer::integerItem($hugeNeg));
        $decodedNeg = Decoder::decode($bytesNeg);
        self::assertTrue(Equality::dxnEquals($hugeNeg, $decodedNeg));
    }

    public function testDecodesStructAsPositional(): void
    {
        $bytes = $this->envelope(Writer::tag(Tags::T_STRUCT, Writer::arrayOf([
            Writer::textItem('Point'),
            Writer::integerItem(1),
            Writer::integerItem(2),
        ])));
        $decoded = Decoder::decode($bytes);
        self::assertEquals(DXNStruct::positional('Point', [1, 2]), $decoded);
    }

    public function testValueSharingResolvesToIndependentEqualCopies(): void
    {
        $shareable = Writer::tag(Tags::T_SHAREABLE, Writer::arrayOf([Writer::integerItem(1)]));
        $sharedRef = Writer::tag(Tags::T_SHARED_REF, Writer::integerItem(0));
        $bytes = $this->envelope(Writer::arrayOf([$shareable, $sharedRef]));
        $decoded = Decoder::decode($bytes);
        self::assertTrue(Equality::dxnEquals($decoded->items[0], $decoded->items[1]));
    }

    public function testSharedRefToNotYetSeenItemThrows(): void
    {
        $bytes = $this->envelope(Writer::tag(Tags::T_SHARED_REF, Writer::integerItem(0)));
        $this->expectException(BinaryError::class);
        Decoder::decode($bytes);
    }

    public function testStringrefSharingResolvesRepeatedText(): void
    {
        $inner = Writer::arrayOf([Writer::textItem('hello'), Writer::tag(Tags::T_STRINGREF, Writer::integerItem(0))]);
        $bytes = $this->envelope(Writer::tag(Tags::T_STRINGREF_NAMESPACE, $inner));
        $decoded = Decoder::decode($bytes);
        self::assertEquals(new DXNList(['hello', 'hello']), $decoded);
    }

    public function testStringrefWithNoActiveNamespaceThrows(): void
    {
        $bytes = $this->envelope(Writer::tag(Tags::T_STRINGREF, Writer::integerItem(0)));
        $this->expectException(BinaryError::class);
        Decoder::decode($bytes);
    }

    public function testInvalidUtf8TextItemThrows(): void
    {
        $bytes = $this->envelope(Writer::head(3, '1') . "\xff");
        $this->expectException(BinaryError::class);
        Decoder::decode($bytes);
    }
}
