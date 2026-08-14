<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Schema\TypeExpr;
use PHPUnit\Framework\Attributes\DataProvider;
use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
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

final class TypeExprTest extends TestCase
{
    /** @return array<string, array{0: string, 1: mixed}> */
    public static function primitiveExamples(): array
    {
        return [
            'nil' => ['nil', null],
            'boolean' => ['boolean', true],
            'integer (native)' => ['integer', 5],
            'integer (bignum)' => ['integer', DXNInteger::fromString('99999999999999999999999')],
            'float' => ['float', 1.5],
            'decimal' => ['decimal', DXNDecimal::fromString('1.5')],
            'rational' => ['rational', new DXNRational('1', '2')],
            'string' => ['string', 'hi'],
            'char' => ['char', new DXNChar(0x61)],
            'symbol' => ['symbol', new DXNSymbol('a')],
            'keyword' => ['keyword', new DXNKeyword('a')],
            'list' => ['list', new DXNList([1])],
            'tuple' => ['tuple', new DXNTuple([1])],
            'map' => ['map', ['a' => 1]],
            'ordered-map' => ['ordered-map', new DXNOrderedMap([])],
            'set' => ['set', new DXNSet([1])],
            'sorted-set' => ['sorted-set', new DXNSortedSet([1])],
            'array' => ['array', [1, 2]],
            'date' => ['date', new DXNDate(2024, 1, 1)],
            'time' => ['time', new DXNTime(1, 2, 3, 0)],
            'timestamp' => ['timestamp', new DXNTimestamp(0)],
            'datetime' => ['datetime', new DXNDateTime(0, 0)],
            'duration' => ['duration', new DXNDuration(days: 1)],
            'uuid' => ['uuid', new DXNUuid(str_repeat("\x00", 16))],
            'uri' => ['uri', new DXNUri('a')],
            'bytes' => ['bytes', new DXNBytes('a')],
            'regex' => ['regex', new DXNRegex('a')],
        ];
    }

    #[DataProvider('primitiveExamples')]
    public function testPrimitiveMatchesItsOwnKindAndRejectsUnrelatedKinds(string $name, mixed $value): void
    {
        self::assertTrue(TypeExpr::primitive($name)->matches($value));
        $unrelated = $name === 'string' ? 42 : 'not-this-kind';
        self::assertFalse(TypeExpr::primitive($name)->matches($unrelated));
    }

    public function testAnyMatchesEverything(): void
    {
        self::assertTrue(TypeExpr::any()->matches(null));
        self::assertTrue(TypeExpr::any()->matches(new DXNList([1, 2])));
    }

    public function testEmptyArrayReadsAsArrayNotMap(): void
    {
        self::assertTrue(TypeExpr::primitive('array')->matches([]));
        self::assertFalse(TypeExpr::primitive('map')->matches([]));
    }

    public function testListOfChecksEveryElement(): void
    {
        $type = TypeExpr::listOf(TypeExpr::primitive('integer'));
        self::assertTrue($type->matches(new DXNList([1, 2, 3])));
        self::assertFalse($type->matches(new DXNList([1, 'two'])));
        self::assertFalse($type->matches([1, 2, 3]), 'a bare array is not a list');
    }

    public function testSetOfChecksEveryElement(): void
    {
        $type = TypeExpr::setOf(TypeExpr::primitive('string'));
        self::assertTrue($type->matches(new DXNSet(['a', 'b'])));
        self::assertFalse($type->matches(new DXNSet(['a', 1])));
    }

    public function testTupleOfChecksPositionalTypesAndLength(): void
    {
        $type = TypeExpr::tupleOf([TypeExpr::primitive('keyword'), TypeExpr::primitive('integer')]);
        self::assertTrue($type->matches(new DXNTuple([new DXNKeyword('ok'), 200])));
        self::assertFalse($type->matches(new DXNTuple([new DXNKeyword('ok')])), 'wrong length');
        self::assertFalse($type->matches(new DXNTuple([200, new DXNKeyword('ok')])), 'wrong order');
    }

    public function testMapOfChecksKeysAndValues(): void
    {
        $type = TypeExpr::mapOf(TypeExpr::primitive('string'), TypeExpr::primitive('integer'));
        self::assertTrue($type->matches(['a' => 1, 'b' => 2]));
        self::assertFalse($type->matches(['a' => 'not-an-int']));
    }

    public function testEnumMatchesAnyListedLiteral(): void
    {
        $type = TypeExpr::enum([new DXNKeyword('red'), new DXNKeyword('green')]);
        self::assertTrue($type->matches(new DXNKeyword('red')));
        self::assertFalse($type->matches(new DXNKeyword('blue')));
    }

    public function testOneOfMatchesAnyVariant(): void
    {
        $type = TypeExpr::oneOf([TypeExpr::primitive('nil'), TypeExpr::primitive('integer')]);
        self::assertTrue($type->matches(null));
        self::assertTrue($type->matches(5));
        self::assertFalse($type->matches('nope'));
    }

    public function testAllOfRequiresEveryVariant(): void
    {
        $type = TypeExpr::allOf([
            TypeExpr::primitive('integer'),
            TypeExpr::refine(TypeExpr::primitive('integer'), ['min' => 0]),
        ]);
        self::assertTrue($type->matches(5));
        self::assertFalse($type->matches(-5));
        self::assertFalse($type->matches('not-an-int'));
    }

    public function testNilableAcceptsNilOrTheElementType(): void
    {
        $type = TypeExpr::nilable(TypeExpr::primitive('string'));
        self::assertTrue($type->matches(null));
        self::assertTrue($type->matches('hi'));
        self::assertFalse($type->matches(5));
    }

    public function testRefineMinMax(): void
    {
        $type = TypeExpr::refine(TypeExpr::primitive('integer'), ['min' => 1, 'max' => 10]);
        self::assertTrue($type->matches(1));
        self::assertTrue($type->matches(10));
        self::assertFalse($type->matches(0));
        self::assertFalse($type->matches(11));
    }

    public function testRefineExclusiveMinMax(): void
    {
        $type = TypeExpr::refine(TypeExpr::primitive('float'), ['exclusive-min' => 0.0, 'exclusive-max' => 1.0]);
        self::assertFalse($type->matches(0.0));
        self::assertTrue($type->matches(0.5));
        self::assertFalse($type->matches(1.0));
    }

    public function testRefineMultipleOf(): void
    {
        $type = TypeExpr::refine(TypeExpr::primitive('integer'), ['multiple-of' => 5]);
        self::assertTrue($type->matches(10));
        self::assertFalse($type->matches(11));
    }

    public function testRefineMinMaxLength(): void
    {
        $type = TypeExpr::refine(TypeExpr::primitive('string'), ['min-length' => 2, 'max-length' => 4]);
        self::assertFalse($type->matches('a'));
        self::assertTrue($type->matches('abc'));
        self::assertFalse($type->matches('abcde'));
    }

    public function testRefinePattern(): void
    {
        $type = TypeExpr::refine(TypeExpr::primitive('string'), ['pattern' => new DXNRegex('^[a-z]+$')]);
        self::assertTrue($type->matches('abc'));
        self::assertFalse($type->matches('ABC'));
    }

    public function testRefineMinMaxCount(): void
    {
        $type = TypeExpr::refine(TypeExpr::primitive('list'), ['min-count' => 1, 'max-count' => 2]);
        self::assertFalse($type->matches(new DXNList([])));
        self::assertTrue($type->matches(new DXNList([1])));
        self::assertFalse($type->matches(new DXNList([1, 2, 3])));
    }

    public function testReferenceMatchesAnOpaqueStructByItsWireName(): void
    {
        $type = TypeExpr::reference('Point');
        self::assertTrue($type->matches(DXNStruct::keyed('Point', [['x', 1]])));
        self::assertFalse($type->matches(DXNStruct::keyed('NotPoint', [['x', 1]])));
    }

    public function testReferenceIsPermissiveForOrdinaryValuesWithNoBetterInformation(): void
    {
        // No registry, not a DXNStruct/Validated -- reference checks
        // can't refute this, so they default to accepting it (matching
        // the JS port's own behavior).
        self::assertTrue(TypeExpr::reference('Point')->matches(42));
    }

    public function testIsPrimitiveName(): void
    {
        self::assertTrue(TypeExpr::isPrimitiveName('integer'));
        self::assertFalse(TypeExpr::isPrimitiveName('not-a-primitive'));
        self::assertFalse(TypeExpr::isPrimitiveName('any'), 'any is handled as its own form, not a primitive name');
    }
}
