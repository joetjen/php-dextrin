<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNMap;
use PHPUnit\Framework\TestCase;

/**
 * Lists and sequences, the superset's group 4 (design/schema-superset.md,
 * Jan, 2026-10-20), as dextrin f2639a9: a list's items in order
 * (`{:list-items ...}` with `{:repeat ...}` and `{:group ...}`), a tuple
 * of any length (`{:tuple-of-all T}`), and the refine keys `contains`,
 * `min-contains`, `max-contains`, `unique` and `unique-by`.
 */
final class SequenceTest extends TestCase
{
    /** A registry where the named type `T` is `$type`. */
    private static function compiled(string $type): Registry
    {
        return Schema::compile(Dextrin::decode("%{T: {$type}}"), new Registry());
    }

    /** Whether the value written `$text` is a `$type`. */
    private static function takes(string $type, string $text): bool
    {
        $registry = self::compiled($type);
        try {
            return Schema::check(Dextrin::decode($text), $registry, 'T');
        } catch (\RuntimeException) {
            return false;
        }
    }

    /** The message `$type` is refused with when it compiles, a `schema/compile` error. */
    private static function compileError(string $type): string
    {
        try {
            self::compiled($type);
        } catch (\Throwable $err) {
            $category = match (true) {
                $err instanceof HasCategory => $err->category(),
                $err instanceof DXNError => $err->category,
                default => null,
            };
            self::assertSame('schema/compile', $category);
            return $err->getMessage();
        }
        self::fail("{$type} should be refused");
    }

    /** A map's values, whatever kind of map it is, sorted by their canonical text. */
    private static function sortedValues(mixed $map): array
    {
        $values = $map instanceof DXNMap
            ? array_map(static fn (array $pair): mixed => $pair[1], $map->pairs())
            : array_values((array) $map);
        usort($values, static fn (mixed $a, mixed $b): int => strcmp(var_export($a, true), var_export($b, true)));
        return $values;
    }

    // ---- {:list-items ...} -------------------------------------------------------

    public function testATypeStandsForExactlyOneItemInOrder(): void
    {
        $type = '{:list-items :string :integer}';
        self::assertTrue(self::takes($type, '["a" 1]'));
        self::assertFalse(self::takes($type, '[1 "a"]'));
        self::assertFalse(self::takes($type, '["a"]'));
        self::assertFalse(self::takes($type, '["a" 1 2]'));
        self::assertFalse(self::takes($type, '{"a" 1}'));
    }

    public function testARepeatTakesBetweenMinAndMaxItemsNilForNoUpperBound(): void
    {
        $headAndTail = '{:list-items :string {:repeat :integer 0 nil}}';
        self::assertTrue(self::takes($headAndTail, '["a"]'));
        self::assertTrue(self::takes($headAndTail, '["a" 1 2 3]'));
        self::assertFalse(self::takes($headAndTail, '["a" 1 "b"]'));

        $twoOrThree = '{:list-items {:repeat :integer 2 3}}';
        self::assertFalse(self::takes($twoOrThree, '[1]'));
        self::assertTrue(self::takes($twoOrThree, '[1 2]'));
        self::assertTrue(self::takes($twoOrThree, '[1 2 3]'));
        self::assertFalse(self::takes($twoOrThree, '[1 2 3 4]'));
    }

    public function testAGroupRepeatsSeveralItemsTogether(): void
    {
        $pairs = '{:list-items {:repeat {:group :string :integer} 1 nil}}';
        self::assertTrue(self::takes($pairs, '["a" 1 "b" 2]'));
        self::assertFalse(self::takes($pairs, '["a" 1 "b"]'));
        self::assertFalse(self::takes($pairs, '[]'));
    }

    public function testARepeatGivesBackItemsTheRestNeeds(): void
    {
        $type = '{:list-items {:repeat :any 0 nil} :string}';
        self::assertTrue(self::takes($type, '[1 2 "end"]'));
        self::assertFalse(self::takes($type, '[1 2 3]'));
    }

    public function testAGroupThatCanMatchNothingStillEnds(): void
    {
        $type = '{:list-items {:repeat {:group {:repeat :integer 0 nil}} 2 nil}}';
        self::assertTrue(self::takes($type, '[]'));
        self::assertTrue(self::takes($type, '[1 2]'));
    }

    public function testAShapesDefaultsAreFilledInTheItemsItMatched(): void
    {
        $registry = Schema::compile(Dextrin::decode(<<<'DXN'
            %{
              T: {:list-items Line {:repeat Line 0 nil}}
              Line: %shape{sku: :string, qty: %field{type: :integer, default: 1}}
            }
            DXN), new Registry());
        $conformed = Schema::conform(Dextrin::decode('[%{sku: "a"} %{sku: "b", qty: 2}]'), $registry, 'T');
        self::assertInstanceOf(DXNList::class, $conformed);
        self::assertCount(2, $conformed->items);
        [$first, $second] = $conformed->items;
        self::assertSame(self::sortedValues([1, 'a']), self::sortedValues($first));
        self::assertSame(self::sortedValues([2, 'b']), self::sortedValues($second));
    }

    public function testARepeatOrAGroupStandsOnlyWhereItemsAreCounted(): void
    {
        self::assertStringContainsString('repeat', self::compileError('{:repeat :integer 0 nil}'));
        self::assertStringContainsString('group', self::compileError('{:list-items {:group :integer :string}}'));
        self::assertStringContainsString('repeat', self::compileError('{:list-items {:repeat :integer 3 2}}'));
        self::assertStringContainsString('repeat', self::compileError('{:list-items {:repeat :integer -1 nil}}'));
    }

    public function testItGuardsANamedTypesRecursionAsListOfDoes(): void
    {
        self::assertInstanceOf(Registry::class, self::compiled('{:list-items :string {:repeat T 0 nil}}'));
    }

    // ---- {:tuple-of-all T} ---------------------------------------------------------

    public function testATupleOfAnyLengthEveryItemAT(): void
    {
        self::assertTrue(self::takes('{:tuple-of-all :integer}', '{}'));
        self::assertTrue(self::takes('{:tuple-of-all :integer}', '{1 2 3}'));
        self::assertFalse(self::takes('{:tuple-of-all :integer}', '{1 "a"}'));
        self::assertFalse(self::takes('{:tuple-of-all :integer}', '[1 2]'));
    }

    // ---- contains --------------------------------------------------------------------

    public function testContainsAtLeastOneItemIsAT(): void
    {
        $type = '{:refine {:list-of :any} %{contains: :string}}';
        self::assertTrue(self::takes($type, '[1 "a"]'));
        self::assertFalse(self::takes($type, '[1 2]'));
        self::assertFalse(self::takes($type, '[]'));
    }

    public function testMinContainsAndMaxContainsCountTheItemsThatAre(): void
    {
        $type = '{:refine {:list-of :any} %{contains: :string, min-contains: 2, max-contains: 3}}';
        self::assertFalse(self::takes($type, '[1 "a"]'));
        self::assertTrue(self::takes($type, '["a" 1 "b"]'));
        self::assertTrue(self::takes($type, '["a" "b" "c"]'));
        self::assertFalse(self::takes($type, '["a" "b" "c" "d"]'));
        self::assertTrue(self::takes('{:refine {:list-of :any} %{contains: :string, min-contains: 0}}', '[1]'));
    }

    public function testContainsIsCheckedOnSetsTuplesSortedSetsAndArraysToo(): void
    {
        self::assertTrue(self::takes('{:refine {:set-of :any} %{contains: :string}}', '@{1 "a"}'));
        self::assertTrue(self::takes('{:refine {:tuple-of-all :any} %{contains: :string}}', '{1 "a"}'));
        self::assertTrue(self::takes('{:refine {:array-of :any} %{contains: :string}}', '@array[1 "a"]'));
    }

    public function testMinContainsOrMaxContainsWithoutContainsIsRefused(): void
    {
        self::assertStringContainsString('contains', self::compileError('{:refine {:list-of :any} %{min-contains: 1}}'));
        self::assertStringContainsString('contains', self::compileError('{:refine {:list-of :any} %{max-contains: 1}}'));
    }

    // ---- unique ------------------------------------------------------------------------

    public function testUniqueNoTwoItemsAreTheSameValue(): void
    {
        self::assertTrue(self::takes('{:refine {:list-of :any} %{unique: true}}', '[1 2 3]'));
        self::assertFalse(self::takes('{:refine {:list-of :any} %{unique: true}}', '[1 2 1]'));
        self::assertTrue(self::takes('{:refine {:list-of :any} %{unique: false}}', '[1 1]'));
        self::assertFalse(self::takes('{:refine {:array-of :any} %{unique: true}}', '@array[1 1]'));
    }

    public function testValuesAreTheSameWhenTheirCanonicalFormsAre1And10Differ(): void
    {
        self::assertTrue(self::takes('{:refine {:list-of :any} %{unique: true}}', '[1 1.0]'));
        self::assertTrue(self::takes('{:refine {:list-of :any} %{unique: true}}', '[%{a: 1} %{"a" => 1}]'));
    }

    public function testUniqueByNoTwoItemsShareThatFieldsValue(): void
    {
        $type = '{:refine {:list-of :any} %{unique-by: :id}}';
        self::assertTrue(self::takes($type, '[%{id: 1} %{id: 2}]'));
        self::assertFalse(self::takes($type, '[%{id: 1} %{"id" => 1}]'));
        self::assertTrue(self::takes($type, '[%{id: 1} %{name: "no id"} %{name: "none either"}]'));
    }

    // ---- in a generic ------------------------------------------------------------------

    public function testAParameterStandsInListItemsAndInContainsAlike(): void
    {
        $registry = Schema::compile(Dextrin::decode(<<<'DXN'
            %{
              NonEmpty: {:generic [t] {:list-items t {:repeat t 0 nil}}}
              Tagged: {:generic [t] {:refine {:list-of :any} %{contains: t}}}
              T: %schema{names: {:of NonEmpty :string}, tags: {:of Tagged :integer}}
            }
            DXN), new Registry());
        Dextrin::decode('%T{names: ["a"], tags: ["x" 1]}', $registry);
        foreach (['%T{names: [], tags: ["x" 1]}', '%T{names: ["a"], tags: ["x"]}'] as $bad) {
            try {
                Dextrin::decode($bad, $registry);
                self::fail("{$bad} should be refused");
            } catch (\RuntimeException $err) {
                self::assertNotSame('', $err->getMessage());
            }
        }
    }

    // ---- refine keys -------------------------------------------------------------------

    public function testMinCountAndMaxCountCountAnArraysItemsToo(): void
    {
        self::assertTrue(self::takes('{:refine {:array-of :any} %{min-count: 2}}', '@array[1 2]'));
        self::assertFalse(self::takes('{:refine {:array-of :any} %{max-count: 1}}', '@array[1 2]'));
    }

    public function testAnUnknownKeyIsRefusedWhenTheSchemaCompiles(): void
    {
        self::assertStringContainsString('min-lenght', self::compileError('{:refine :string %{min-lenght: 1}}'));
    }
}
