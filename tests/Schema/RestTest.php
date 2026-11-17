<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use PHPUnit\Framework\TestCase;

/**
 * G1 of dextrin's design/typespec.md: typed extras and closed bodies,
 * written `* K => V` and `- *` in a `%schema` or `%shape` body (DXNS.md
 * §4c), as the reference's rest_test.exs.
 */
final class RestTest extends TestCase
{
    private const REST = <<<'DXN'
        %{
          Tags: %shape{name: :string, * :string => :integer}
          Box: %schema{tags: Tags}
          Counts: %schema{total: :integer, * :string => :integer}
        }
        DXN;

    private const CLOSED = <<<'DXN'
        %{
          Point: %shape{x: :integer, y: :integer - *}
          Pin: %schema{at: Point - *}
        }
        DXN;

    private static function compiled(string $text, ?Registry $registry = null): Registry
    {
        return Schema::compile(Dextrin::decode($text), $registry ?? new Registry());
    }

    /** The message `$text` is refused with, a `schema/compile` error. */
    private static function compileError(string $text): string
    {
        try {
            self::compiled($text);
        } catch (\Throwable $err) {
            self::assertSame('schema/compile', self::categoryOf($err), $err->getMessage());
            return $err->getMessage();
        }
        self::fail("{$text} should be refused");
    }

    private static function categoryOf(\Throwable $err): ?string
    {
        return match (true) {
            $err instanceof HasCategory => $err->category(),
            $err instanceof DXNError => $err->category,
            default => null,
        };
    }

    /** The category `$run` fails with, or `ok` when it does not. */
    private static function category(callable $run): string
    {
        try {
            $run();
        } catch (\Throwable $err) {
            return self::categoryOf($err) ?? $err::class;
        }
        return 'ok';
    }

    /** The message `$run` fails with. */
    private static function message(callable $run): string
    {
        try {
            $run();
        } catch (\Throwable $err) {
            return $err->getMessage();
        }
        self::fail('expected a refusal');
    }

    /** Whether the definition `$name` takes the value `$text` decodes to. */
    private static function takes(Registry $registry, string $name, string $text): bool
    {
        return self::category(static fn () => Schema::check(Dextrin::decode($text), $registry, $name)) === 'ok';
    }

    // ---- * K => V types the extras ----------------------------------------------

    public function testAnExtraOfAShapeMustMatchTheRest(): void
    {
        $r = self::compiled(self::REST);
        self::assertTrue(self::takes($r, 'Tags', '%{name: "a", "x" => 1, "y" => 2}'));
        self::assertFalse(self::takes($r, 'Tags', '%{name: "a", "x" => "one"}'));
        self::assertFalse(self::takes($r, 'Tags', '%{name: "a", :x => 1}'));
        self::assertTrue(self::takes($r, 'Tags', '%{name: "a"}'));
    }

    public function testADeclaredFieldIsNoExtraWhateverTheRestSays(): void
    {
        self::assertTrue(self::takes(self::compiled(self::REST), 'Tags', '%{name: "a"}'));
    }

    public function testAStructsExtrasMatchTheRestAndAStrictDecodeKeepsThem(): void
    {
        $r = self::compiled(self::REST);
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%Counts{total: 1, "a" => 2}', $r)));
        self::assertSame('schema/type-mismatch', self::category(static fn () => Dextrin::decode('%Counts{total: 1, "a" => "x"}', $r)));
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%Counts{total: 1, "a" => 2}', $r, strict: true)));
    }

    public function testKAndVMayNameTheDocumentsOtherTypes(): void
    {
        $r = self::compiled('%{Count: {:refine :integer %{min: 0}}, T: %shape{a: :integer, * :string => Count}}');
        self::assertTrue(self::takes($r, 'T', '%{a: 1, "x" => 2}'));
        self::assertFalse(self::takes($r, 'T', '%{a: 1, "x" => -2}'));
    }

    public function testARefusedExtraSaysWhy(): void
    {
        $r = self::compiled(self::REST);
        self::assertStringContainsString('"a"', self::message(static fn () => Dextrin::decode('%Counts{total: 1, "a" => "x"}', $r)));
    }

    // ---- - * refuses every extra ----------------------------------------------------

    public function testAClosedShapeTakesNoExtra(): void
    {
        $r = self::compiled(self::CLOSED);
        self::assertTrue(self::takes($r, 'Point', '%{x: 1, y: 2}'));
        self::assertFalse(self::takes($r, 'Point', '%{x: 1, y: 2, z: 3}'));
    }

    public function testAClosedSchemaRefusesAnExtraEvenWithoutAStrictDecode(): void
    {
        $r = self::compiled(self::CLOSED);
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%Pin{at: %{x: 1, y: 2}}', $r)));
        self::assertSame('schema/undeclared-field', self::category(static fn () => Dextrin::decode('%Pin{at: %{x: 1, y: 2}, note: 1}', $r)));
    }

    public function testClosedDoesNotGoWithTypedExtras(): void
    {
        self::assertStringContainsString('* K => V', self::compileError('%{P: %shape{x: :integer - * * :string => :any}}'));
    }

    // ---- written and read back ------------------------------------------------------

    public function testBothEntriesPrintAsWrittenAndReadBackTheSame(): void
    {
        $text = '%{P: %shape{- * x: :integer}, Q: %schema{* :string => :integer, y: :string}}';
        $doc = Dextrin::decode($text);
        foreach ([Dextrin::encode($doc), Dextrin::encode($doc, pretty: true), \JOetjen\Dextrin\Canonical::print($doc)] as $printed) {
            self::assertStringContainsString('- *', $printed);
            self::assertStringContainsString('* :string => :integer', str_replace(':string=>:integer', ':string => :integer', $printed));
            self::assertTrue(\JOetjen\Dextrin\Value\Equality::dxnEquals($doc, Dextrin::decode($printed)), $printed);
        }
    }

    public function testTheDocumentFormatterLaysThemOutAsWritten(): void
    {
        $source = "%{P: %shape{x: :integer\n-   *\n*   :string   =>   :integer}}";
        self::assertSame("%{\n  P: %shape{x: :integer - * * :string => :integer}\n}", \JOetjen\Dextrin\Document::parse($source)->format());
        self::assertSame(
            "%{\n  P: %shape{\n    x: :integer\n    - *\n    * :string =>  # why\n      :integer\n  }\n}",
            \JOetjen\Dextrin\Document::parse("%{P: %shape{x: :integer - * * :string => # why\n :integer}}")->format(),
        );
    }

    public function testTheyHaveNoBinaryForm(): void
    {
        foreach (['%schema{- * x: :integer}', '%shape{* :string => :integer}'] as $text) {
            self::assertSame('encode/unsupported', self::category(static fn () => Dextrin::encodeBinary(Dextrin::decode($text))), $text);
        }
    }

    public function testOnlyASchemaOrAShapeTakesThem(): void
    {
        self::assertStringContainsString('%Foo', self::message(static fn () => Dextrin::decode('%Foo{- *}')));
        self::assertStringContainsString('%Foo', self::message(static fn () => Dextrin::decode('%Foo{* :a => 1}')));
    }

    public function testABodyTakesOneTypedExtrasAtMost(): void
    {
        self::assertStringContainsString('once', self::message(static fn () => Dextrin::decode('%shape{* :string => :any * :keyword => :any}')));
    }

    public function testClosedStandsOnceInABody(): void
    {
        self::assertStringContainsString('once', self::message(static fn () => Dextrin::decode('%{P: %shape{x: :integer - * - *}}')));
    }

    public function testAStarBeginsNothingElse(): void
    {
        self::assertSame('syntax', self::category(static fn () => Dextrin::decode('[* 1]')));
        self::assertSame('syntax', self::category(static fn () => Dextrin::decode('%{* :a => 1}')));
    }

    // ---- built from others ----------------------------------------------------------

    public function testTheRestAndClosedAreInheritedAndTheBodysOwnReplaceThem(): void
    {
        $r = self::compiled(<<<'DXN'
            %{
              Base: %shape{a: :integer, * :string => :integer}
              Kept: %shape includes Base {b: :integer}
              Mine: %shape includes Base {b: :integer, * :string => :string}
            }
            DXN);
        self::assertFalse(self::takes($r, 'Kept', '%{a: 1, b: 2, "x" => "s"}'));
        self::assertTrue(self::takes($r, 'Mine', '%{a: 1, b: 2, "x" => "s"}'));
    }

    public function testTwoBasesWhoseRestDiffersAreACompileErrorUnlessTheBodySettlesIt(): void
    {
        self::assertStringContainsString('extras', self::compileError(<<<'DXN'
            %{
              A: %shape{a: :integer, * :string => :integer}
              B: %shape{b: :integer - *}
              C: %shape includes A B {}
            }
            DXN));
        self::assertSame('ok', self::category(static fn () => self::compiled(<<<'DXN'
            %{
              A: %shape{a: :integer, * :string => :integer}
              B: %shape{b: :integer - *}
              C: %shape includes A B {- *}
            }
            DXN)));
    }

    public function testExtendingAClosedBaseWithAFieldOfItsOwnIsACompileError(): void
    {
        self::assertStringContainsString('closed', self::compileError(<<<'DXN'
            %{
              A: %schema{a: :integer - *}
              B: %schema extends A {b: :integer}
            }
            DXN));
    }
}
