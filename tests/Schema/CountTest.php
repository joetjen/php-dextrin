<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use PHPUnit\Framework\TestCase;

/**
 * `min-count` and `max-count` on maps, shapes and structs (T674 of
 * dextrin's design/json-schema.md): JSON Schema's minProperties and
 * maxProperties, counting the entries present. As the reference's
 * count_test.exs.
 */
final class CountTest extends TestCase
{
    private static function registry(string $text): Registry
    {
        return Schema::compile(Dextrin::decode($text), new Registry());
    }

    private static function takes(Registry $registry, string $name, string $text): bool
    {
        try {
            Schema::check(Dextrin::decode($text), $registry, $name);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function decodes(string $text, Registry $registry): bool
    {
        try {
            Dextrin::decode($text, $registry);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function testAMapCountsItsEntries(): void
    {
        $r = self::registry('%{M: {:refine {:map-of :string :integer} %{min-count: 1 max-count: 2}}}');
        self::assertFalse(self::takes($r, 'M', '%{}'));
        self::assertTrue(self::takes($r, 'M', '%{"a" => 1}'));
        self::assertTrue(self::takes($r, 'M', '%{"a" => 1 "b" => 2}'));
        self::assertFalse(self::takes($r, 'M', '%{"a" => 1 "b" => 2 "c" => 3}'));
    }

    public function testAMapWhosePhpFormIsADxnMapCountsItsEntries(): void
    {
        $r = self::registry('%{M: {:refine :map %{max-count: 1}}}');
        self::assertTrue(self::takes($r, 'M', '%{1 => :a}'));
        self::assertFalse(self::takes($r, 'M', '%{1 => :a 2 => :b}'));
    }

    public function testAShapeCountsTheFieldsPresentAndItsExtras(): void
    {
        $r = self::registry('%{S: {:refine %shape{a?: :integer b?: :integer} %{min-count: 1 max-count: 2}}}');
        self::assertFalse(self::takes($r, 'S', '%{}'));
        self::assertTrue(self::takes($r, 'S', '%{a: 1}'));
        self::assertFalse(self::takes($r, 'S', '%{a: 1 b: 2 "c" => 3}'));
    }

    public function testAnOrderedMapCountsAsAMapDoes(): void
    {
        $r = self::registry('%{M: {:refine :ordered-map %{max-count: 1}}}');
        self::assertTrue(self::takes($r, 'M', '@ordered %{a: 1}'));
        self::assertFalse(self::takes($r, 'M', '@ordered %{a: 1 b: 2}'));
    }

    public function testAStructCountsItsFieldsPresentAndItsExtras(): void
    {
        $r = self::registry(<<<'DXN'
            %{
              P: %schema{a?: :integer b?: :integer}
              Holder: %schema{p: {:refine P %{min-count: 1}}}
            }
            DXN);
        self::assertTrue(self::decodes('%Holder{p: %P{a: 1}}', $r));
        self::assertFalse(self::decodes('%Holder{p: %P{}}', $r));
    }

    public function testContainsAndUniqueStillApplyToCollectionsOnly(): void
    {
        $r = self::registry('%{M: {:refine :map %{unique: true}}}');
        self::assertFalse(self::takes($r, 'M', '%{"a" => 1}'));
    }
}
