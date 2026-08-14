<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests;

use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Text\ParseError;
use JOetjen\Dextrin\Value\DXNKeyword;
use PHPUnit\Framework\TestCase;

final class MapBuilderTest extends TestCase
{
    private function build(array $pairs): array
    {
        return MapBuilder::build($pairs, static fn (string $m): ParseError => new ParseError($m));
    }

    public function testKeywordKeyStaysUnprefixedAndErgonomic(): void
    {
        $result = $this->build([[new DXNKeyword('x'), 1]]);
        self::assertSame(['x' => 1], $result);
    }

    public function testStringKeyIsMarkedDistinctFromASameTextKeyword(): void
    {
        $result = $this->build([[new DXNKeyword('x'), 1], ['x', 2]]);
        self::assertSame(['x' => 1, "\0x" => 2], $result);
    }

    public function testCanonicalIntegerStringKeyIsNotMarked(): void
    {
        // Left alone so it keeps colliding with a bare `integer` key
        // of the same value -- see testIntAndCanonicalNumericStringKeyStillCollide().
        $result = $this->build([['5', 'value']]);
        self::assertSame([5 => 'value'], $result);
    }

    public function testNonCanonicalNumericLookingStringKeyIsMarked(): void
    {
        // "01" is not a canonical integer string (PHP would never
        // auto-coerce it to int 1), so it's marked like any other
        // genuine string key.
        $result = $this->build([['01', 'value']]);
        self::assertSame(["\0" . '01' => 'value'], $result);
    }

    public function testIntAndCanonicalNumericStringKeyStillCollide(): void
    {
        $this->expectException(ParseError::class);
        $this->build([[5, 'a'], ['5', 'b']]);
    }

    public function testKeywordAndStringKeyNoLongerCollide(): void
    {
        $result = $this->build([[new DXNKeyword('name'), 1], ['name', 2]]);
        self::assertSame(['name' => 1, "\0name" => 2], $result);
    }

    public function testDuplicateIdenticalKeysAreLastWriteWins(): void
    {
        self::assertSame(['x' => 2], $this->build([[new DXNKeyword('x'), 1], [new DXNKeyword('x'), 2]]));
        self::assertSame(["\0x" => 2], $this->build([['x', 1], ['x', 2]]));
    }

    public function testToDxnMapKeyRecoversAKeywordForAnUnmarkedStringKey(): void
    {
        $key = MapBuilder::toDxnMapKey('x');
        self::assertEquals(new DXNKeyword('x'), $key);
    }

    public function testToDxnMapKeyRecoversAPlainStringForAMarkedKey(): void
    {
        self::assertSame('x', MapBuilder::toDxnMapKey("\0x"));
    }

    public function testToDxnMapKeyPassesIntKeysThroughUnchanged(): void
    {
        self::assertSame(5, MapBuilder::toDxnMapKey(5));
    }

    public function testToDxnMapKeyIsTheExactInverseOfBuildForBothKeyKinds(): void
    {
        $result = $this->build([[new DXNKeyword('x'), 1], ['x', 2]]);
        foreach ($result as $phpKey => $unusedValue) {
            $recovered = MapBuilder::toDxnMapKey($phpKey);
            self::assertTrue(
                ($phpKey === 'x' && $recovered == new DXNKeyword('x'))
                || ($phpKey === "\0x" && $recovered === 'x'),
            );
        }
    }
}
