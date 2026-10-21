<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
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
use JOetjen\Dextrin\Value\DXNLocalDateTime;
use JOetjen\Dextrin\Value\DXNList;
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
            'local-datetime' => ['local-datetime', new DXNLocalDateTime(0)],
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

    /**
     * A plain PHP array key is a keyword, as the printer and the encoder
     * write it; a string key is the one MapBuilder marks.
     */
    public function testMapOfChecksKeysAndValues(): void
    {
        $strings = TypeExpr::mapOf(TypeExpr::primitive('string'), TypeExpr::primitive('integer'));
        self::assertTrue($strings->matches([MapBuilder::markStringKey('a') => 1, MapBuilder::markStringKey('b') => 2]));
        self::assertFalse($strings->matches([MapBuilder::markStringKey('a') => 'not-an-int']));
        self::assertFalse($strings->matches(['a' => 1]), 'a plain key is a keyword');

        $keywords = TypeExpr::mapOf(TypeExpr::primitive('keyword'), TypeExpr::primitive('integer'));
        self::assertTrue($keywords->matches(['a' => 1, 'b' => 2]));
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

    public function testAReferenceRefusesAValueThatIsNotAStruct(): void
    {
        // A struct is matched only by a struct (DXN.md §4.7): an array or
        // any other value is not one, however much it looks like it.
        self::assertFalse(TypeExpr::reference('Point')->matches(42));
        self::assertFalse(TypeExpr::reference('Point')->matches(['x' => 1]));
    }

    /** A registry where `T` has one field `v` of type `$type`. */
    private static function registryFor(string $type): Registry
    {
        return Schema::compile(Dextrin::decode("%{T: %schema{v: {$type}}}"), new Registry());
    }

    /** Whether `%T{v: $value}` decodes. */
    private static function takes(Registry $registry, string $value): bool
    {
        try {
            Dextrin::decode("%T{v: {$value}}", $registry);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // One datetime type whatever its offset; :timestamp is a datetime in
    // UTC (Jan, 2026-10-20).
    public function testTimestampTakesAUtcDatetimeOnlyAndDatetimeTakesAnyOffset(): void
    {
        $r = self::registryFor(':timestamp');
        self::assertTrue(self::takes($r, '@datetime "2024-01-01T00:00:00Z"'));
        self::assertFalse(self::takes($r, '@datetime "2024-01-01T00:00:00+02:00"'));

        $r = self::registryFor(':datetime');
        self::assertTrue(self::takes($r, '@datetime "2024-01-01T00:00:00Z"'));
        self::assertTrue(self::takes($r, '@datetime "2024-01-01T00:00:00+02:00"'));
    }

    public function testOffsetsNamesTheOffsetsADatetimeMayHave(): void
    {
        $r = self::registryFor('{:refine :datetime %{offsets: ["Z", "+02:00"]}}');
        self::assertTrue(self::takes($r, '@datetime "2024-01-01T00:00:00+02:00"'));
        self::assertFalse(self::takes($r, '@datetime "2024-01-01T00:00:00-05:00"'));
    }

    public function testFiniteRefusesNanAndTheInfinitiesAndARangeRefusesNan(): void
    {
        $r = self::registryFor('{:refine :float %{finite: true}}');
        self::assertTrue(self::takes($r, '1.5'));
        foreach (['NaN', 'Infinity', '-Infinity'] as $bad) {
            self::assertFalse(self::takes($r, $bad), $bad);
        }

        $r = self::registryFor('{:refine :float %{min: 0}}');
        self::assertTrue(self::takes($r, 'Infinity'));
        self::assertFalse(self::takes($r, 'NaN'));
        self::assertFalse(self::takes($r, '-Infinity'));

        $r = self::registryFor('{:refine :float %{max: 0}}');
        self::assertFalse(self::takes($r, 'NaN'));
    }

    public function testIsPrimitiveName(): void
    {
        self::assertTrue(TypeExpr::isPrimitiveName('integer'));
        self::assertFalse(TypeExpr::isPrimitiveName('not-a-primitive'));
        self::assertFalse(TypeExpr::isPrimitiveName('any'), 'any is handled as its own form, not a primitive name');
    }
}
