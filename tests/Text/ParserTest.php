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
use JOetjen\Dextrin\Value\DXNLocalDateTime;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTime;
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

    public function testMapKeysThatWouldCollideMakeADxnMap(): void
    {
        $map = Parser::parse('%{1 => "a", "1" => "b"}');
        self::assertInstanceOf(\JOetjen\Dextrin\Value\DXNMap::class, $map);
        self::assertSame('a', $map->get(1));
        self::assertSame('b', $map->get('1'));
    }

    public function testMapKeywordAndSameTextStringKeyCoexistDistinctly(): void
    {
        // A keyword key and a same-text string key no longer collide
        // -- MapBuilder marks the string one (a leading NUL byte, see
        // its own doc), so `name:` and `"name"` land in distinct PHP
        // array slots instead of being forced to share one.
        self::assertSame(['name' => 1, "\0name" => 2], Parser::parse('%{name: 1, "name" => 2}'));
    }

    public function testMapCollectionKeyIsNotAllowed(): void
    {
        $this->expectException(\JOetjen\Dextrin\KeyNotAllowed::class);
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

    public function testParsesTemporalTags(): void
    {
        self::assertEquals(new DXNDate(2024, 6, 1), Parser::parse('@date "2024-06-01"'));
        self::assertEquals(new DXNTime(13, 45, 30), Parser::parse('@time "13:45:30"'));
        self::assertEquals(new DXNDateTime(1_717_249_530_000_000_000, 0), Parser::parse('@datetime "2024-06-01T13:45:30Z"'));
        self::assertInstanceOf(DXNDateTime::class, Parser::parse('@datetime "2024-06-01T13:45:30+02:00"'));
        self::assertInstanceOf(DXNLocalDateTime::class, Parser::parse('@local-datetime "2024-06-01T13:45:30"'));
        self::assertInstanceOf(DXNDuration::class, Parser::parse('@duration "P1Y"'));
    }

    public function testParsesARegexItsFlagsInAHeader(): void
    {
        $re = Parser::parse('@regex(im) "foo"');
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

    public function testAStructEntryWhoseKeyIsNotANameIsAnExtraField(): void
    {
        // An arrow-form struct entry keyed by a non-name is an extra field
        // (DXN.md §4.7), not a field and not an error.
        $value = Parser::parse('%Point{1 => 2}');
        self::assertInstanceOf(\JOetjen\Dextrin\Value\DXNStruct::class, $value);
        self::assertSame([], $value->pairs());
        self::assertSame([[1, 2]], $value->extra);
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
