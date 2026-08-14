<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Schema\Coercion;
use JOetjen\Dextrin\Schema\NotCoercible;
use JOetjen\Dextrin\Schema\TypeExpr;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNTimestamp;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUri;
use JOetjen\Dextrin\Value\DXNUuid;
use PHPUnit\Framework\TestCase;

final class CoercionTest extends TestCase
{
    public function testFloatCoercesToRationalAndDecimal(): void
    {
        self::assertEquals(new DXNRational('1', '2'), Coercion::tryCoerce(TypeExpr::primitive('rational'), 0.5));
        self::assertEquals(DXNDecimal::fromFloat(1.5), Coercion::tryCoerce(TypeExpr::primitive('decimal'), 1.5));
    }

    public function testIntDoesNotCoerceToRationalOrDecimal(): void
    {
        // Mirrors the JS port exactly: only a native `float` source
        // coerces here, matching JS's own `typeof v === 'number'`
        // (never `bigint`/PHP `int`) gate.
        self::assertInstanceOf(NotCoercible::class, Coercion::tryCoerce(TypeExpr::primitive('rational'), 5));
    }

    public function testStringCoercesToChar(): void
    {
        self::assertEquals(new DXNChar(0x61), Coercion::tryCoerce(TypeExpr::primitive('char'), 'a'));
    }

    public function testMultiCodepointStringFailsToCoerceToChar(): void
    {
        self::assertInstanceOf(NotCoercible::class, Coercion::tryCoerce(TypeExpr::primitive('char'), 'ab'));
    }

    public function testSequentialArrayCoercesToListAndTuple(): void
    {
        self::assertEquals(new DXNList([1, 2]), Coercion::tryCoerce(TypeExpr::primitive('list'), [1, 2]));
        self::assertEquals(new DXNTuple([1, 2]), Coercion::tryCoerce(TypeExpr::primitive('tuple'), [1, 2]));
    }

    public function testAssociativeArrayCoercesToOrderedMap(): void
    {
        $coerced = Coercion::tryCoerce(TypeExpr::primitive('ordered-map'), ['a' => 1, 'b' => 2]);
        self::assertEquals(new DXNOrderedMap([['a', 1], ['b', 2]]), $coerced);
    }

    public function testArrayOrSetCoercesToSortedSet(): void
    {
        self::assertEquals(DXNSortedSet::fromArray([2, 1]), Coercion::tryCoerce(TypeExpr::primitive('sorted-set'), [2, 1]));
        self::assertEquals(DXNSortedSet::fromArray([2, 1]), Coercion::tryCoerce(TypeExpr::primitive('sorted-set'), new DXNSet([2, 1])));
    }

    public function testDXNListCoercesToBareArray(): void
    {
        self::assertSame([1, 2], Coercion::tryCoerce(TypeExpr::primitive('array'), new DXNList([1, 2])));
    }

    public function testDateTimeImmutableCoercesToDateTimestampAndDatetime(): void
    {
        $date = new \DateTimeImmutable('2024-01-01T12:00:00+00:00');
        self::assertEquals(DXNDate::fromDateTimeImmutable($date), Coercion::tryCoerce(TypeExpr::primitive('date'), $date));
        self::assertEquals(DXNTimestamp::fromDateTimeImmutable($date), Coercion::tryCoerce(TypeExpr::primitive('timestamp'), $date));
        self::assertEquals(DXNDateTime::fromDateTimeImmutable($date), Coercion::tryCoerce(TypeExpr::primitive('datetime'), $date));
    }

    public function testStringCoercesToUuidUriAndRegex(): void
    {
        $uuidString = '550e8400-e29b-41d4-a716-446655440000';
        self::assertEquals(DXNUuid::fromString($uuidString), Coercion::tryCoerce(TypeExpr::primitive('uuid'), $uuidString));
        self::assertEquals(DXNUri::fromString('https://example.com'), Coercion::tryCoerce(TypeExpr::primitive('uri'), 'https://example.com'));
        self::assertEquals(DXNRegex::fromPcrePattern('/abc/i'), Coercion::tryCoerce(TypeExpr::primitive('regex'), '/abc/i'));
    }

    public function testInvalidUuidStringFailsToCoerceRatherThanThrow(): void
    {
        self::assertInstanceOf(NotCoercible::class, Coercion::tryCoerce(TypeExpr::primitive('uuid'), 'not-a-uuid'));
    }

    public function testUnknownPrimitiveHasNoCoercion(): void
    {
        self::assertInstanceOf(NotCoercible::class, Coercion::tryCoerce(TypeExpr::primitive('string'), 5));
        self::assertInstanceOf(NotCoercible::class, Coercion::tryCoerce(TypeExpr::primitive('boolean'), 5));
    }

    public function testListOfCoercesElementWise(): void
    {
        $type = TypeExpr::listOf(TypeExpr::primitive('rational'));
        $coerced = Coercion::tryCoerce($type, [0.5, 0.25]);
        self::assertEquals(new DXNList([new DXNRational('1', '2'), new DXNRational('1', '4')]), $coerced);
    }

    public function testListOfLeavesNonCoercibleElementsUnchangedForTheOuterCheckToJudge(): void
    {
        $type = TypeExpr::listOf(TypeExpr::primitive('rational'));
        $coerced = Coercion::tryCoerce($type, [0.5, 'not-coercible']);
        self::assertEquals(new DXNList([new DXNRational('1', '2'), 'not-coercible']), $coerced);
    }

    public function testSetOfCoercesElementWise(): void
    {
        $type = TypeExpr::setOf(TypeExpr::primitive('rational'));
        self::assertEquals(new DXNSet([new DXNRational('1', '2')]), Coercion::tryCoerce($type, [0.5]));
    }

    public function testTupleOfCoercesPositionallyAndRejectsWrongLength(): void
    {
        $type = TypeExpr::tupleOf([TypeExpr::primitive('rational'), TypeExpr::primitive('char')]);
        self::assertEquals(new DXNTuple([new DXNRational('1', '2'), new DXNChar(0x61)]), Coercion::tryCoerce($type, [0.5, 'a']));
        self::assertInstanceOf(NotCoercible::class, Coercion::tryCoerce($type, [0.5]));
    }

    public function testNilableSkipsCoercionForNilAndDelegatesOtherwise(): void
    {
        $type = TypeExpr::nilable(TypeExpr::primitive('rational'));
        self::assertInstanceOf(NotCoercible::class, Coercion::tryCoerce($type, null));
        self::assertEquals(new DXNRational('1', '2'), Coercion::tryCoerce($type, 0.5));
    }

    public function testOneOfTriesEachVariantInOrder(): void
    {
        $type = TypeExpr::oneOf([TypeExpr::primitive('char'), TypeExpr::primitive('rational')]);
        self::assertEquals(new DXNRational('1', '2'), Coercion::tryCoerce($type, 0.5));
        self::assertInstanceOf(NotCoercible::class, Coercion::tryCoerce($type, []));
    }

    public function testRefineDelegatesToItsBaseType(): void
    {
        $type = TypeExpr::refine(TypeExpr::primitive('rational'), ['min' => 0]);
        self::assertEquals(new DXNRational('1', '2'), Coercion::tryCoerce($type, 0.5));
    }

    public function testAnyAndReferenceHaveNoCoercion(): void
    {
        self::assertInstanceOf(NotCoercible::class, Coercion::tryCoerce(TypeExpr::any(), 5));
        self::assertInstanceOf(NotCoercible::class, Coercion::tryCoerce(TypeExpr::reference('Foo'), 5));
    }
}
