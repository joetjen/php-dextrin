<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\Text\ParseError;
use JOetjen\Dextrin\Text\Parser;
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
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testParsesScalars(): void
    {
        self::assertNull(Parser::parse('nil'));
        self::assertTrue(Parser::parse('true'));
        self::assertFalse(Parser::parse('false'));
        self::assertSame(42, Parser::parse('42'));
        self::assertSame(-17, Parser::parse('-17'));
        self::assertSame(3.14, Parser::parse('3.14'));
        self::assertNan(Parser::parse('NaN'));
        self::assertSame(INF, Parser::parse('Infinity'));
        self::assertSame(-INF, Parser::parse('-Infinity'));
        self::assertSame('hello', Parser::parse('"hello"'));
    }

    public function testParsesIntegerHybridOverflow(): void
    {
        $huge = Parser::parse('99999999999999999999999999999');
        self::assertInstanceOf(DXNInteger::class, $huge);
        self::assertSame('99999999999999999999999999999', $huge->toString());
    }

    public function testParsesDecimalAndRational(): void
    {
        $d = Parser::parse('19.99M');
        self::assertInstanceOf(DXNDecimal::class, $d);
        self::assertSame('19.99', $d->toString());

        $r = Parser::parse('22/7');
        self::assertInstanceOf(DXNRational::class, $r);
        self::assertSame('22', $r->numerator);
        self::assertSame('7', $r->denominator);
    }

    public function testParsesCharLiteral(): void
    {
        self::assertEquals(new DXNChar(0x61), Parser::parse('?a'));
        self::assertEquals(new DXNChar(0x20), Parser::parse('?s'));
        self::assertEquals(new DXNChar(0x0a), Parser::parse('?\\n'));
    }

    public function testCharLiteralRejectsBareSpace(): void
    {
        $this->expectException(ParseError::class);
        Parser::parse('? ');
    }

    public function testParsesSymbolAndKeyword(): void
    {
        self::assertEquals(new DXNSymbol('foo'), Parser::parse('foo'));
        self::assertEquals(new DXNKeyword('bar'), Parser::parse(':bar'));
        self::assertEquals(new DXNKeyword('has space'), Parser::parse(':"has space"'));
    }

    public function testParsesListTupleSet(): void
    {
        self::assertEquals(new DXNList([1, 2, 3]), Parser::parse('[1 2 3]'));
        self::assertEquals(new DXNTuple([1, 2, 3]), Parser::parse('{1 2 3}'));
        self::assertEquals(new DXNSet([1, 2, 3]), Parser::parse('@{1 2 3}'));
    }

    public function testParsesMapAsBareArray(): void
    {
        self::assertSame(['a' => 1, 'b' => 2], Parser::parse('%{a: 1, b: 2}'));
    }

    public function testMapKeywordAndArrowShorthandAreEquivalent(): void
    {
        self::assertSame(Parser::parse('%{a: 1}'), Parser::parse('%{:a => 1}'));
    }

    public function testMapKeyCollisionThrows(): void
    {
        $this->expectException(ParseError::class);
        Parser::parse('%{1 => "a", "1" => "b"}');
    }

    public function testMapKeywordVsStringKeyCollisionThrows(): void
    {
        $this->expectException(ParseError::class);
        Parser::parse('%{name: 1, "name" => 2}');
    }

    public function testMapNonScalarKeyThrows(): void
    {
        $this->expectException(ParseError::class);
        Parser::parse('%{[1 2] => "a"}');
    }

    public function testMapDuplicateIdenticalKeyIsLastWriteWins(): void
    {
        self::assertSame(['a' => 2], Parser::parse('%{a: 1, a: 2}'));
    }

    public function testParsesOrderedMap(): void
    {
        $om = Parser::parse('@ordered %{b: 2, a: 1}');
        self::assertInstanceOf(DXNOrderedMap::class, $om);
        self::assertEquals([[new DXNKeyword('b'), 2], [new DXNKeyword('a'), 1]], $om->pairs);
    }

    public function testParsesSortedSet(): void
    {
        $ss = Parser::parse('@sorted-set @{3 1 2}');
        self::assertInstanceOf(DXNSortedSet::class, $ss);
        self::assertSame([1, 2, 3], $ss->items);
    }

    public function testParsesArrayTagAsBareSequentialArray(): void
    {
        self::assertSame([1, 2, 3], Parser::parse('@array[1 2 3]'));
    }

    public function testParsesKeyedAndPositionalStruct(): void
    {
        $keyed = Parser::parse('%Point{x: 1, y: 2}');
        self::assertInstanceOf(DXNStruct::class, $keyed);
        self::assertSame('Point', $keyed->name);
        self::assertSame([['x', 1], ['y', 2]], $keyed->pairs());

        $positional = Parser::parse('%Point[1, 2]');
        self::assertSame([1, 2], $positional->values());
    }

    public function testParsesBuiltInExtendedTags(): void
    {
        self::assertEquals(
            DXNUuid::fromString('550e8400-e29b-41d4-a716-446655440000'),
            Parser::parse('@uuid "550e8400-e29b-41d4-a716-446655440000"'),
        );
        self::assertEquals(new DXNUri('https://example.com'), Parser::parse('@uri "https://example.com"'));
        self::assertEquals(new DXNBytes('hi'), Parser::parse('@bytes "aGk="'));
    }

    public function testParsesTemporalSigilsAndTags(): void
    {
        self::assertEquals(new DXNDate(2024, 6, 1), Parser::parse('~D[2024-06-01]'));
        self::assertEquals(new DXNTime(13, 45, 30), Parser::parse('~T[13:45:30]'));
        self::assertInstanceOf(DXNTimestamp::class, Parser::parse('~U[2024-06-01 13:45:30Z]'));
        self::assertInstanceOf(DXNDateTime::class, Parser::parse('@datetime "2024-06-01T13:45:30+02:00"'));
        self::assertInstanceOf(DXNDuration::class, Parser::parse('@duration "P1Y"'));
    }

    public function testParsesRegexSigil(): void
    {
        $re = Parser::parse('~r/foo/im');
        self::assertInstanceOf(DXNRegex::class, $re);
        self::assertSame('foo', $re->pattern);
        self::assertTrue($re->flags['i']);
        self::assertTrue($re->flags['m']);
    }

    public function testParsesCustomTag(): void
    {
        $tag = Parser::parse('@my-app/money 500');
        self::assertInstanceOf(DXNCustomTag::class, $tag);
        self::assertSame('my-app/money', $tag->name);
        self::assertSame(500, $tag->value);
    }

    public function testDiscardIsResolvedAwayEntirely(): void
    {
        self::assertSame(2, Parser::parse('@_ 1 2'));
        self::assertEquals(new DXNList([2, 3]), Parser::parse('[@_ 1 2 3]'));
    }

    public function testHeaderIsOptionalAndConsumed(): void
    {
        self::assertSame(42, Parser::parse('@dxn "1.0" 42'));
        self::assertSame(42, Parser::parse('42'));
    }

    public function testTrailingGarbageAfterValueThrows(): void
    {
        $this->expectException(ParseError::class);
        Parser::parse('42 43');
    }

    public function testStructFieldNameMustResolveToString(): void
    {
        // Arrow-form struct field key using a non-string, non-keyword value.
        $this->expectException(ParseError::class);
        Parser::parse('%Point{1 => 2}');
    }

    public function testOrderedRequiresAMapLiteralArgument(): void
    {
        $this->expectException(ParseError::class);
        Parser::parse('@ordered [1 2]');
    }

    public function testSortedSetRequiresASetLiteralArgument(): void
    {
        $this->expectException(ParseError::class);
        Parser::parse('@sorted-set [1 2]');
    }

    public function testArrayTagRequiresAListLiteralArgument(): void
    {
        $this->expectException(ParseError::class);
        Parser::parse('@array {1 2}');
    }
}
