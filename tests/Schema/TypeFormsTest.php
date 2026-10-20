<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The type forms Jan added on 2026-10-20, as dextrin 47d6d1d: one-of
 * means exactly one variant matches (JSON Schema's oneOf), any-of one or
 * more (anyOf); not, never, and element types for arrays, ordered maps and
 * sorted sets.
 */
final class TypeFormsTest extends TestCase
{
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

    public function testOneOfRefusesAValueTwoVariantsMatch(): void
    {
        $r = self::registryFor('{:one-of :integer {:refine :integer %{min: 0}}}');
        self::assertTrue(self::takes($r, '-1'));
        self::assertFalse(self::takes($r, '5'));
    }

    public function testAnyOfTakesAValueOneOrMoreVariantsMatch(): void
    {
        $r = self::registryFor('{:any-of :integer {:refine :integer %{min: 0}}}');
        self::assertTrue(self::takes($r, '-1'));
        self::assertTrue(self::takes($r, '5'));
        self::assertFalse(self::takes($r, '"x"'));
    }

    public function testNotTakesWhatTheTypeRefuses(): void
    {
        $r = self::registryFor('{:not :string}');
        self::assertTrue(self::takes($r, '1'));
        self::assertFalse(self::takes($r, '"x"'));
    }

    public function testNeverTakesNothingAtAll(): void
    {
        $r = self::registryFor(':never');
        self::assertFalse(self::takes($r, '1'));
        self::assertFalse(self::takes($r, 'nil'));
    }

    public function testTheyNestInEachOtherAndAsElementTypes(): void
    {
        $r = self::registryFor('{:list-of {:one-of :string {:any-of :integer :decimal}}}');
        self::assertTrue(self::takes($r, '["a" 1]'));
        self::assertFalse(self::takes($r, '[true]'));
    }

    public function testArrayOf(): void
    {
        $r = self::registryFor('{:array-of :integer}');
        self::assertTrue(self::takes($r, '@array[1 2]'));
        self::assertFalse(self::takes($r, '@array[1 "x"]'));
        self::assertFalse(self::takes($r, '[1 2]'));
    }

    public function testOrderedMapOf(): void
    {
        $r = self::registryFor('{:ordered-map-of :keyword :integer}');
        self::assertTrue(self::takes($r, '@ordered %{a: 1 b: 2}'));
        self::assertFalse(self::takes($r, '@ordered %{a: "x"}'));
        self::assertFalse(self::takes($r, '%{a: 1}'));
    }

    public function testSortedSetOf(): void
    {
        $r = self::registryFor('{:sorted-set-of :integer}');
        self::assertTrue(self::takes($r, '@sorted-set @{2 1}'));
        self::assertFalse(self::takes($r, '@sorted-set @{1 "x"}'));
        self::assertFalse(self::takes($r, '@{1 2}'));
    }
}
