<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\Equality;
use PHPUnit\Framework\TestCase;

final class EqualityTest extends TestCase
{
    public function testKindOfNativeScalars(): void
    {
        self::assertSame('nil', Equality::kindOf(null));
        self::assertSame('boolean', Equality::kindOf(true));
        self::assertSame('integer', Equality::kindOf(1));
        self::assertSame('float', Equality::kindOf(1.0));
        self::assertSame('string', Equality::kindOf('a'));
    }

    public function testKindOfSplitsArrayIntoArrayVsMapByShape(): void
    {
        self::assertSame('array', Equality::kindOf([1, 2, 3]));
        self::assertSame('array', Equality::kindOf([]));
        self::assertSame('map', Equality::kindOf(['a' => 1]));
        self::assertSame('map', Equality::kindOf([1 => 'a', 0 => 'b'])); // out-of-order int keys: not a list
    }

    public function testKindOfWrappedClassesDelegateToDxnType(): void
    {
        self::assertSame('symbol', Equality::kindOf(new DXNSymbol('a')));
        self::assertSame('list', Equality::kindOf(new DXNList([])));
    }

    public function testKindOfAnyPlainObjectIsStruct(): void
    {
        self::assertSame('struct', Equality::kindOf(new \stdClass()));

        $obj = new class {
            public int $x = 1;
        };
        self::assertSame('struct', Equality::kindOf($obj));
    }

    public function testDxnEqualsRequiresSameKind(): void
    {
        self::assertFalse(Equality::dxnEquals(1, '1'));
        self::assertFalse(Equality::dxnEquals([1, 2], ['a' => 1]));
    }

    public function testDxnEqualsNanEqualsItself(): void
    {
        self::assertTrue(Equality::dxnEquals(NAN, NAN));
        self::assertFalse(Equality::dxnEquals(1.0, NAN));
    }

    public function testDxnEqualsMapIsOrderIndependent(): void
    {
        self::assertTrue(Equality::dxnEquals(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]));
    }

    public function testDxnEqualsArrayIsOrderSensitive(): void
    {
        self::assertFalse(Equality::dxnEquals([1, 2], [2, 1]));
    }

    public function testDxnEqualsRecursesThroughNestedNativeAndWrappedValues(): void
    {
        self::assertTrue(Equality::dxnEquals(['a' => new DXNSymbol('x')], ['a' => new DXNSymbol('x')]));
    }

    public function testDxnEqualsPlainObjectsCompareByClassAndPublicProperties(): void
    {
        $a = (object) ['x' => 1, 'y' => 2];
        $b = (object) ['y' => 2, 'x' => 1];
        self::assertTrue(Equality::dxnEquals($a, $b));

        $c = new class {
            public int $x = 1;
        };
        self::assertFalse(Equality::dxnEquals($a, $c), 'different classes must never compare equal');
    }

    public function testDxnCompareCrossKindUsesFixedRankOrder(): void
    {
        self::assertLessThan(0, Equality::dxnCompare(null, true));
        self::assertLessThan(0, Equality::dxnCompare(true, 1));
        self::assertLessThan(0, Equality::dxnCompare(1, 1.0));
        self::assertLessThan(0, Equality::dxnCompare('a', new DXNSymbol('a')));
        self::assertGreaterThan(0, Equality::dxnCompare(new DXNSymbol('a'), 'a'));
    }

    public function testDxnCompareSameKindNativeScalars(): void
    {
        self::assertLessThan(0, Equality::dxnCompare(1, 2));
        self::assertLessThan(0, Equality::dxnCompare('a', 'b'));
        self::assertSame(0, Equality::dxnCompare(1, 1));
    }

    public function testDxnCompareArrayIsLexicographic(): void
    {
        self::assertLessThan(0, Equality::dxnCompare([1, 2], [1, 3]));
        self::assertLessThan(0, Equality::dxnCompare([1], [1, 2]), 'shorter prefix sorts first');
    }

    public function testCanonicalKeyIsStableAndDistinguishesKinds(): void
    {
        self::assertSame(Equality::canonicalKey(1), Equality::canonicalKey(1));
        self::assertNotSame(Equality::canonicalKey(1), Equality::canonicalKey('1'));
        self::assertNotSame(Equality::canonicalKey([1, 2]), Equality::canonicalKey(['a' => 1, 'b' => 2]));
    }

    public function testCanonicalKeyForMapIsOrderIndependent(): void
    {
        self::assertSame(
            Equality::canonicalKey(['a' => 1, 'b' => 2]),
            Equality::canonicalKey(['b' => 2, 'a' => 1]),
        );
    }

    public function testCanonicalKeyForStructKeyedIsOrderIndependent(): void
    {
        $a = DXNStruct::keyed('Point', [['x', 1], ['y', 2]]);
        $b = DXNStruct::keyed('Point', [['y', 2], ['x', 1]]);
        self::assertSame(Equality::canonicalKey($a), Equality::canonicalKey($b));
    }
}
