<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNMap;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use PHPUnit\Framework\TestCase;

/**
 * DXN.md §1.6: a map key is a string, keyword, integer, uuid, date,
 * bytes, symbol or char, and anything else is refused as key/not-allowed
 * -- in text, in binary, and when encoding. Two keys are the same key
 * exactly when their canonical forms are.
 */
final class MapKeyTest extends TestCase
{
    private static function categoryOf(\Closure $failing): ?string
    {
        try {
            $failing();
        } catch (DXNError $err) {
            return $err->category;
        }
        self::fail('expected a DXNError');
    }

    public function testAMapOfOrdinaryKeysIsStillANativeArray(): void
    {
        self::assertSame(['x' => 1, 'y' => 2], Dextrin::decode('%{x: 1, y: 2}'));
    }

    public function testKeysANativeArrayCannotHoldMakeADxnMap(): void
    {
        $map = Dextrin::decode('%{?c => 1, @date "2026-10-17" => 2, @uuid "6f9619ff-8b86-d011-b42d-00c04fc964ff" => 3}');
        self::assertInstanceOf(DXNMap::class, $map);
        self::assertCount(3, $map);
        self::assertSame(1, $map->get(new DXNChar(0x63)));
    }

    public function testAnIntegerAndItsStringAreTwoKeys(): void
    {
        $map = Dextrin::decode('%{1 => :a, "1" => :b}');
        self::assertCount(2, $map);
        self::assertSame('%{"1" => :b 1 => :a}', Canonical::print($map));
    }

    public function testAStringKeyThatLooksLikeAnIntegerStaysAString(): void
    {
        self::assertSame('%{"5" => 1}', Canonical::print(Dextrin::decode('%{"5" => 1}')));
        self::assertSame('%{"5" => 1}', Canonical::print(Dextrin::decodeBinary(Dextrin::encodeBinary(Dextrin::decode('%{"5" => 1}')))));
    }

    public function testAnEmptyMapIsAMapAndNotAnArray(): void
    {
        self::assertSame('%{}', Canonical::print(Dextrin::decode('%{}')));
        self::assertSame('%{0 => :a}', Canonical::print(Dextrin::decode('%{0 => :a}')));
    }

    public function testARepeatedKeyKeepsItsLastValue(): void
    {
        self::assertSame('%{?c => 2}', Canonical::print(Dextrin::decode('%{?c => 1, ?c => 2}')));
    }

    public function testFloatNilBooleanNanAndListKeysAreRefusedInText(): void
    {
        foreach (['%{1.5 => 1}', '%{nil => 1}', '%{true => 1}', '%{NaN => 1}', '%{[1 2] => 1}', '@ordered %{1.5 => 1}'] as $text) {
            self::assertSame('key/not-allowed', self::categoryOf(static fn () => Dextrin::decode($text)), $text);
        }
    }

    public function testFloatAndNilKeysAreRefusedInBinary(): void
    {
        self::assertSame('key/not-allowed', self::categoryOf(static fn () => Dextrin::decodeBinary((string) hex2bin('445801a1fb3ff800000000000001'))));
        self::assertSame('key/not-allowed', self::categoryOf(static fn () => Dextrin::decodeBinary((string) hex2bin('445801a1f601'))));
    }

    public function testADisallowedKeyIsRefusedWhenEncoding(): void
    {
        $map = new DXNOrderedMap([[1.5, 1]]);
        self::assertSame('key/not-allowed', self::categoryOf(static fn () => Dextrin::encode($map)));
        self::assertSame('key/not-allowed', self::categoryOf(static fn () => Dextrin::encodeBinary($map)));
    }

    public function testADxnMapRoundTripsThroughTextAndBinary(): void
    {
        $map = Dextrin::decode('%{?c => 1, 1 => 2, "1" => 3}');
        self::assertSame(Canonical::print($map), Canonical::print(Dextrin::decode(Dextrin::encode($map))));
        self::assertSame(Canonical::print($map), Canonical::print(Dextrin::decodeBinary(Dextrin::encodeBinary($map))));
    }
}
