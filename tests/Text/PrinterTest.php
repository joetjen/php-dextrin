<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\Compiled;
use JOetjen\Dextrin\Schema\Field;
use JOetjen\Dextrin\Text\Parser;
use JOetjen\Dextrin\Text\Printer;
use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDecimal;
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
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUri;
use PHPUnit\Framework\TestCase;

final class PrinterTest extends TestCase
{
    public function testPrintsScalars(): void
    {
        self::assertSame('nil', Printer::print(null));
        self::assertSame('true', Printer::print(true));
        self::assertSame('false', Printer::print(false));
        self::assertSame('42', Printer::print(42));
        self::assertSame('"hello"', Printer::print('hello'));
        self::assertSame('NaN', Printer::print(NAN));
        self::assertSame('Infinity', Printer::print(INF));
        self::assertSame('-Infinity', Printer::print(-INF));
    }

    public function testPrintsFloatAlwaysWithDecimalPointOrExponent(): void
    {
        self::assertSame('1.0', Printer::print(1.0));
        self::assertSame('3.14', Printer::print(3.14));
    }

    public function testPrintsIntegerHybridBoth(): void
    {
        self::assertSame('42', Printer::print(42));
        self::assertSame('99999999999999999999', Printer::print(DXNInteger::fromString('99999999999999999999')));
    }

    public function testPrintsDecimalAndRational(): void
    {
        self::assertSame('19.99M', Printer::print(new DXNDecimal(1, '1999', -2)));
        self::assertSame('22/7', Printer::print(new DXNRational('22', '7')));
    }

    public function testPrintsCharWithSpecialBodies(): void
    {
        self::assertSame('?a', Printer::print(new DXNChar(0x61)));
        self::assertSame('?s', Printer::print(new DXNChar(0x20)));
        self::assertSame('?\\n', Printer::print(new DXNChar(0x0a)));
    }

    public function testPrintsSymbolAndKeyword(): void
    {
        self::assertSame('foo', Printer::print(new DXNSymbol('foo')));
        self::assertSame(':foo', Printer::print(new DXNKeyword('foo')));
        self::assertSame(':"has space"', Printer::print(new DXNKeyword('has space')));
    }

    public function testPrintsListTupleSet(): void
    {
        self::assertSame('[1 2 3]', Printer::print(new DXNList([1, 2, 3])));
        self::assertSame('{1 2 3}', Printer::print(new DXNTuple([1, 2, 3])));
        self::assertSame('@{1 2 3}', Printer::print(new DXNSet([1, 2, 3])));
    }

    public function testPrintsBareArrayAsArrayTag(): void
    {
        self::assertSame('@array[1 2 3]', Printer::print([1, 2, 3]));
    }

    public function testPrintsBareAssocArrayAsMap(): void
    {
        self::assertSame('%{a:1,b:2}', Printer::print(['a' => 1, 'b' => 2]));
    }

    public function testPrintsIntegerKeyedMapEntryViaArrowForm(): void
    {
        self::assertSame('%{1=>"a"}', Printer::print([1 => 'a']));
    }

    public function testPrintsAMarkedStringMapKeyAsAQuotedArrowEntryNotKeywordShorthand(): void
    {
        // MapBuilder's own leading-NUL marker for a genuinely
        // string-typed key (see its doc) -- must print as a quoted
        // string, not silently become a keyword on re-encode.
        self::assertSame('%{"a"=>1}', Printer::print(["\0a" => 1]));
    }

    public function testPrintedMarkedStringKeyDecodesBackToTheSameMarkedKey(): void
    {
        $value = ["\0a" => 1];
        self::assertSame($value, Parser::parse(Printer::print($value)));
    }

    public function testPrintsOrderedMapAsTagForm(): void
    {
        self::assertSame(
            '@ordered %{b:2,a:1}',
            Printer::print(new DXNOrderedMap([[new DXNKeyword('b'), 2], [new DXNKeyword('a'), 1]])),
        );
    }

    public function testPrintsSortedSetAsTagForm(): void
    {
        self::assertSame('@sorted-set @{1 2 3}', Printer::print(new DXNSortedSet([3, 1, 2])));
    }

    public function testPrintsKeyedAndPositionalStruct(): void
    {
        self::assertSame('%Point{x:1,y:2}', Printer::print(DXNStruct::keyed('Point', [['x', 1], ['y', 2]])));
        self::assertSame('%Point[1,2]', Printer::print(DXNStruct::positional('Point', [1, 2])));
    }

    public function testPrintsPlainObjectAsStructConvenience(): void
    {
        self::assertSame('%stdClass{x:1,y:2}', Printer::print((object) ['x' => 1, 'y' => 2]));
    }

    public function testPrintsPlainObjectUsingShortClassName(): void
    {
        $point = new class {
            public int $x = 1;
        };
        self::assertStringStartsWith('%', Printer::print($point));
        self::assertStringNotContainsString('\\', Printer::print($point));
    }

    public function testPrintsExtendedTagForms(): void
    {
        self::assertSame('@uri "https://example.com"', Printer::print(new DXNUri('https://example.com')));
        self::assertSame('@bytes "aGk="', Printer::print(new DXNBytes('hi')));
    }

    public function testPrintsRegex(): void
    {
        self::assertSame('~r/foo/im', Printer::print(new DXNRegex('foo', ['i' => true, 'm' => true])));
    }

    public function testPrintsCustomTag(): void
    {
        self::assertSame('@my-app/money 500', Printer::print(new DXNCustomTag('my-app/money', 500)));
    }

    public function testPrintsDateSigil(): void
    {
        self::assertSame('~D[2024-06-01]', Printer::print(new DXNDate(2024, 6, 1)));
    }

    public function testQuoteStringEscapesControlCharsAndQuotes(): void
    {
        self::assertSame('"line1\\nline2"', Printer::quoteString("line1\nline2"));
    }

    public function testIsBareIdentifierAcceptsIdentifiersOnly(): void
    {
        self::assertTrue(Printer::isBareIdentifier('foo'));
        self::assertTrue(Printer::isBareIdentifier('foo-bar?'));
        self::assertFalse(Printer::isBareIdentifier(''));
        self::assertFalse(Printer::isBareIdentifier('has space'));
        self::assertFalse(Printer::isBareIdentifier('123'));
        self::assertFalse(Printer::isBareIdentifier('nil'));
    }

    public function testThrowsForValueWithNoDxnRepresentation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Printer::print(fn () => 1);
    }

    public function testPrintsARegisteredApplicationInstanceUnderItsSchemaNameNotItsClassName(): void
    {
        $point = new PrinterTestPoint(1, 2);
        $compiled = new Compiled(fields: [new Field('x'), new Field('y')]);
        $registry = (new Registry())
            ->putStructModule('Point', PrinterTestPoint::class)
            ->putStructSchema('Point', $compiled);

        self::assertSame('%Point(1){x:1,y:2}', Printer::print($point, $registry));
        // With no registry, or a registry that doesn't know this
        // class, the class-name convenience still applies unchanged.
        self::assertSame('%PrinterTestPoint{x:1,y:2}', Printer::print($point));
        self::assertSame('%PrinterTestPoint{x:1,y:2}', Printer::print($point, new Registry()));
    }

    public function testPrintsARegisteredTagEncoderInstanceAsATagFormNotAStruct(): void
    {
        $money = new PrinterTestMoney(500);
        $registry = (new Registry())->putTagEncoder(
            PrinterTestMoney::class,
            'my-app/money',
            static fn (PrinterTestMoney $m): int => $m->cents,
        );

        self::assertSame('@my-app/money 500', Printer::print($money, $registry));
        // A struct-schema match, if present, still takes priority over a tag encoder.
        $compiled = new Compiled(fields: [new Field('cents')]);
        $registryWithBoth = $registry
            ->putStructModule('Money', PrinterTestMoney::class)
            ->putStructSchema('Money', $compiled);
        self::assertSame('%Money(1){cents:500}', Printer::print($money, $registryWithBoth));
    }
}

final class PrinterTestMoney
{
    public function __construct(public int $cents)
    {
    }
}

final class PrinterTestPoint
{
    public function __construct(
        public int $x,
        public int $y,
    ) {
    }
}
