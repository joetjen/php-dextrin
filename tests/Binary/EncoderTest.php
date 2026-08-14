<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Binary;

use JOetjen\Dextrin\Binary\Encoder;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\Compiled;
use JOetjen\Dextrin\Schema\Field;
use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNUri;
use PHPUnit\Framework\TestCase;

final class EncoderTest extends TestCase
{
    private function envelope(string $item): string
    {
        return "DX\x01" . $item;
    }

    public function testEncodesEnvelope(): void
    {
        $bytes = Encoder::encode(null);
        self::assertSame('DX', substr($bytes, 0, 2));
        self::assertSame("\x01", $bytes[2]);
    }

    public function testEncodesScalars(): void
    {
        self::assertSame($this->envelope("\xf6"), Encoder::encode(null));
        self::assertSame($this->envelope("\xf4"), Encoder::encode(false));
        self::assertSame($this->envelope("\xf5"), Encoder::encode(true));
        self::assertSame($this->envelope("\x18\x2a"), Encoder::encode(42));
        self::assertSame($this->envelope("\x61\x22"), Encoder::encode('"')); // text item: head(3,1) + the byte '"'
    }

    public function testEncodesListUntagged(): void
    {
        $bytes = Encoder::encode(new DXNList([1, 2]));
        self::assertSame($this->envelope("\x82\x01\x02"), $bytes);
    }

    public function testEncodesTupleTagged(): void
    {
        $bytes = Encoder::encode(new \JOetjen\Dextrin\Value\DXNTuple([1]));
        // tag 203 (T_TUPLE) needs the 1-byte tag extension: head(6, 203)
        self::assertStringStartsWith("DX\x01\xd8\xcb", $bytes);
    }

    public function testEncodesBareSequentialArrayAsArrayTag(): void
    {
        $bytes = Encoder::encode([1, 2]);
        // tag 204 (T_ARRAY): head(6, 204) = 0xd8 0xcc
        self::assertStringStartsWith("DX\x01\xd8\xcc", $bytes);
    }

    public function testEncodesBareAssociativeArrayAsUntaggedMap(): void
    {
        // An un-marked string key (MapBuilder's own convention, see
        // its doc) is a keyword -- the ergonomic, overwhelmingly
        // common case for a hand-built array too -- so it encodes
        // under tag T_KEYWORD (202 = 0xd8 0xca), not as a plain text
        // item.
        $bytes = Encoder::encode(['a' => 1]);
        // major5 count1 (0xa1), tag 202 (0xd8 0xca), key "a" (0x61 0x61), value 1 (0x01)
        self::assertSame($this->envelope("\xa1\xd8\xca\x61\x61\x01"), $bytes);
    }

    public function testEncodesAMarkedStringKeyAsAPlainTextItem(): void
    {
        // MapBuilder's own leading-NUL marker for a genuinely
        // string-typed key (see its doc) -- encodes as a plain,
        // untagged text item, distinct from the keyword case above.
        $bytes = Encoder::encode(["\0a" => 1]);
        // major5 count1 (0xa1), key "a" (0x61 0x61, no tag), value 1 (0x01)
        self::assertSame($this->envelope("\xa1\x61\x61\x01"), $bytes);
    }

    public function testEncodesSymbolAndKeywordViaPrivateTags(): void
    {
        $sym = Encoder::encode(new DXNSymbol('a'));
        // tag 201 (T_SYMBOL): head(6, 201) = 0xd8 0xc9
        self::assertStringStartsWith("DX\x01\xd8\xc9", $sym);

        $kw = Encoder::encode(new DXNKeyword('a'));
        // tag 202 (T_KEYWORD): head(6, 202) = 0xd8 0xca
        self::assertStringStartsWith("DX\x01\xd8\xca", $kw);
    }

    public function testEncodesCharAsCodepointUnderTag(): void
    {
        $bytes = Encoder::encode(new DXNChar(0x61));
        // tag 200 (T_CHAR): head(6, 200) = 0xd8 0xc8, then codepoint 97 = 0x18 0x61
        self::assertSame($this->envelope("\xd8\xc8\x18\x61"), $bytes);
    }

    public function testEncodesDecimalAsExponentMantissaPair(): void
    {
        $bytes = Encoder::encode(new DXNDecimal(-1, '1999', -2));
        // tag 4: head(6,4)=0xc4, array of 2: 0x82, exponent -2 -> integerItem(-2)=0x21, mantissa -1999 -> integerItem(-1999)
        self::assertStringStartsWith("DX\x01\xc4\x82\x21", $bytes);
    }

    public function testEncodesRationalAsNumeratorDenominatorPair(): void
    {
        $bytes = Encoder::encode(new DXNRational('22', '7'));
        // tag 30: head(6,30) needs 1-byte extension = 0xd8 0x1e
        self::assertStringStartsWith("DX\x01\xd8\x1e\x82", $bytes);
    }

    public function testEncodesBytesUntagged(): void
    {
        $bytes = Encoder::encode(new DXNBytes('hi'));
        self::assertSame($this->envelope("\x42\x68\x69"), $bytes);
    }

    public function testEncodesUriUnderTag32(): void
    {
        $bytes = Encoder::encode(new DXNUri('a'));
        // tag 32: head(6,32) = 0xd8 0x20
        self::assertStringStartsWith("DX\x01\xd8\x20", $bytes);
    }

    public function testEncodesStructAlwaysPositional(): void
    {
        $struct = DXNStruct::keyed('Point', [['x', 1], ['y', 2]]);
        $bytes = Encoder::encode($struct);
        // tag 208 (T_STRUCT): head(6,208) = 0xd8 0xd0
        self::assertStringStartsWith("DX\x01\xd8\xd0", $bytes);
    }

    public function testEncodesPlainObjectAsPositionalStructConvenience(): void
    {
        $bytesFromObject = Encoder::encode((object) ['x' => 1, 'y' => 2]);
        $bytesFromStruct = Encoder::encode(DXNStruct::positional('stdClass', [1, 2]));
        self::assertSame($bytesFromStruct, $bytesFromObject);
    }

    public function testEncodesCustomTag(): void
    {
        $bytes = Encoder::encode(new DXNCustomTag('my-tag', 5));
        // tag 214 (T_CUSTOM): head(6,214) = 0xd8 0xd6
        self::assertStringStartsWith("DX\x01\xd8\xd6", $bytes);
    }

    public function testThrowsForClosure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Encoder::encode(fn () => 1);
    }

    public function testThrowsForResource(): void
    {
        $handle = fopen('php://memory', 'r');
        self::assertNotFalse($handle);
        try {
            $this->expectException(\InvalidArgumentException::class);
            Encoder::encode($handle);
        } finally {
            fclose($handle);
        }
    }

    public function testEncodesARegisteredApplicationInstanceUnderItsSchemaNameNotItsClassName(): void
    {
        $point = new EncoderTestPoint(1, 2);
        $compiled = new Compiled(fields: [new Field('x'), new Field('y')]);
        $registry = (new Registry())
            ->putStructModule('Point', EncoderTestPoint::class)
            ->putStructSchema('Point', $compiled);

        $viaRegistry = Encoder::encode($point, $registry);
        $viaExplicitStruct = Encoder::encode(DXNStruct::positional('Point', [1, 2]));
        self::assertSame($viaExplicitStruct, $viaRegistry);

        $viaClassNameOnly = Encoder::encode($point);
        self::assertNotSame($viaRegistry, $viaClassNameOnly);
        self::assertSame(Encoder::encode(DXNStruct::positional('EncoderTestPoint', [1, 2])), $viaClassNameOnly);
    }

    public function testEncodesARegisteredTagEncoderInstanceAsACustomTagNotAStruct(): void
    {
        $money = new EncoderTestMoney(500);
        $registry = (new Registry())->putTagEncoder(
            EncoderTestMoney::class,
            'my-app/money',
            static fn (EncoderTestMoney $m): int => $m->cents,
        );

        $viaRegistry = Encoder::encode($money, $registry);
        $viaExplicitCustomTag = Encoder::encode(new DXNCustomTag('my-app/money', 500));
        self::assertSame($viaExplicitCustomTag, $viaRegistry);
    }
}

final class EncoderTestMoney
{
    public function __construct(public int $cents)
    {
    }
}

final class EncoderTestPoint
{
    public function __construct(
        public int $x,
        public int $y,
    ) {
    }
}
