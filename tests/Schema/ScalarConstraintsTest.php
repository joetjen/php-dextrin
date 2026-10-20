<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\SchemaValidationError;
use PHPUnit\Framework\TestCase;

/**
 * Scalar refine keys, the superset's group 5 (design/schema-superset.md,
 * Jan, 2026-10-20), as dextrin 2f20707: lengths and patterns on more
 * types, a decimal's precision and exponent, single precision, allowed
 * bits, and ranges on dates, times and durations. A constraint on a
 * value it does not apply to refuses it, never raises.
 */
final class ScalarConstraintsTest extends TestCase
{
    /** A registry where the named type `T` is `$type`. */
    private static function compiled(string $type): Registry
    {
        return Schema::compile(Dextrin::decode("%{T: {$type}}"), new Registry());
    }

    /**
     * Whether the value written `$text` is a `$type`. Only a type
     * mismatch answers false: anything else a check raises fails the
     * test, as a constraint never raises.
     */
    private static function takes(string $type, string $text): bool
    {
        $registry = self::compiled($type);
        try {
            return Schema::check(Dextrin::decode($text), $registry, 'T');
        } catch (SchemaValidationError $err) {
            self::assertSame('schema/type-mismatch', $err->category());
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

    // ---- a constraint on a value it does not apply to ----------------------------

    public function testAConstraintOnAValueItDoesNotApplyToRefusesTheValue(): void
    {
        self::assertFalse(self::takes('{:refine :any %{min-length: 1}}', '5'));
        self::assertFalse(self::takes('{:refine :any %{min: 1}}', '"x"'));
        self::assertFalse(self::takes('{:refine :any %{pattern: @regex "a"}}', '5'));
        self::assertFalse(self::takes('{:refine :any %{min-count: 1}}', '5'));
        self::assertFalse(self::takes('{:refine :any %{min-precision: 1}}', '1.5'));
    }

    // ---- compiling -----------------------------------------------------------------

    public function testAKeyAUniqueByOrAResolutionThatIsNoNameIsRefusedNeverRaised(): void
    {
        self::assertStringContainsString('key is a name', self::compileError('{:refine :string %{1 => 2}}'));
        self::assertStringContainsString('unique-by', self::compileError('{:refine {:list-of :any} %{unique-by: 5}}'));
        self::assertStringContainsString(':millisecond', self::compileError('{:refine :time %{resolution: :fortnight}}'));
    }

    // ---- lengths -------------------------------------------------------------------

    public function testAKeywordsOrASymbolsLengthIsItsNamesInCodepoints(): void
    {
        self::assertTrue(self::takes('{:refine :keyword %{max-length: 3}}', ':abc'));
        self::assertFalse(self::takes('{:refine :keyword %{max-length: 3}}', ':abcd'));
        self::assertTrue(self::takes('{:refine :symbol %{min-length: 2}}', 'ab'));
    }

    public function testAByteStringsLengthIsInBytesABitRunsUnderBitsInBits(): void
    {
        self::assertTrue(self::takes('{:refine :bytes %{min-length: 2, max-length: 2}}', '@bytes "AQI="'));
        self::assertFalse(self::takes('{:refine :bytes %{max-length: 1}}', '@bytes "AQI="'));
        self::assertTrue(self::takes('{:refine :bits %{min-length: 12}}', '@bytes "AQI="'));
        self::assertFalse(self::takes('{:refine :bits %{min-length: 17}}', '@bytes "AQI="'));
    }

    public function testMinBytesAndMaxBytesCountAStringInUtf8(): void
    {
        self::assertTrue(self::takes('{:refine :string %{max-length: 1, max-bytes: 2}}', '"é"'));
        self::assertFalse(self::takes('{:refine :string %{max-bytes: 1}}', '"é"'));
        self::assertTrue(self::takes('{:refine :string %{min-bytes: 2}}', '"é"'));
    }

    // ---- pattern -------------------------------------------------------------------

    public function testAPatternMatchesAKeywordsOrASymbolsName(): void
    {
        self::assertTrue(self::takes('{:refine :keyword %{pattern: @regex "^[a-z]+$"}}', ':abc'));
        self::assertFalse(self::takes('{:refine :symbol %{pattern: @regex "^[a-z]+$"}}', 'Abc'));
    }

    public function testAPatternMatchesBytesOneCharacterPerByte(): void
    {
        self::assertTrue(self::takes('{:refine :bytes %{pattern: @regex "^\\\\x01\\\\xff$"}}', '@bytes "Af8="'));
        self::assertFalse(self::takes('{:refine :bytes %{pattern: @regex "^\\\\x01$"}}', '@bytes "Af8="'));
    }

    // ---- a decimal's precision and exponent ------------------------------------------

    public function testPrecisionCountsTheCoefficientsDigitsAsWritten(): void
    {
        self::assertTrue(self::takes('{:refine :decimal %{max-precision: 3}}', '1.50M'));
        self::assertFalse(self::takes('{:refine :decimal %{max-precision: 2}}', '1.50M'));
        self::assertTrue(self::takes('{:refine :decimal %{min-precision: 3}}', '1.50M'));
        self::assertTrue(self::takes('{:refine :decimal %{min-precision: 1, max-precision: 1}}', '0M'));
    }

    public function testTheExponentIsThePowerOfTenTheCoefficientIsScaledBy(): void
    {
        self::assertTrue(self::takes('{:refine :decimal %{min-exponent: -2, max-exponent: -2}}', '1.50M'));
        self::assertFalse(self::takes('{:refine :decimal %{min-exponent: -1}}', '1.50M'));
    }

    // ---- float32 -------------------------------------------------------------------

    public function testTheFloatHoldsInSinglePrecisionExactly(): void
    {
        self::assertTrue(self::takes('{:refine :float %{float32: true}}', '1.5'));
        self::assertFalse(self::takes('{:refine :float %{float32: true}}', '0.1'));
        self::assertFalse(self::takes('{:refine :float %{float32: true}}', '1.0e300'));
        self::assertTrue(self::takes('{:refine :float %{float32: true}}', 'Infinity'));
        self::assertTrue(self::takes('{:refine :float %{float32: false}}', '0.1'));
    }

    // ---- bits ----------------------------------------------------------------------

    public function testAnUnsignedIntegerSetsOnlyTheBitsGiven(): void
    {
        $rwx = '{:refine :integer %{bits: [0 1 2]}}';
        self::assertTrue(self::takes($rwx, '5'));
        self::assertTrue(self::takes($rwx, '0'));
        self::assertFalse(self::takes($rwx, '8'));
        self::assertFalse(self::takes($rwx, '-1'));
    }

    public function testAByteStringNumbersItsBitsFromTheFirstBytesLowest(): void
    {
        $flags = '{:refine :bytes %{bits: [0 9]}}';
        self::assertTrue(self::takes($flags, '@bytes "AQI="'));
        self::assertFalse(self::takes($flags, '@bytes "Ag=="'));
        self::assertTrue(self::takes($flags, '@bytes ""'));
    }

    // ---- ranges on dates, times and durations ----------------------------------------

    public function testMinAndMaxCompareValuesOfTheSameKind(): void
    {
        $type = '{:refine :date %{min: @date "2026-01-01", exclusive-max: @date "2027-01-01"}}';
        self::assertTrue(self::takes($type, '@date "2026-10-20"'));
        self::assertFalse(self::takes($type, '@date "2027-01-01"'));
        self::assertFalse(self::takes($type, '@date "2025-12-31"'));
    }

    public function testDatetimesCompareAsInstantsWhateverTheirOffsets(): void
    {
        $type = '{:refine :datetime %{max: @datetime "2026-10-20T12:00:00Z"}}';
        self::assertTrue(self::takes($type, '@datetime "2026-10-20T13:00:00+01:00"'));
        self::assertFalse(self::takes($type, '@datetime "2026-10-20T12:00:01Z"'));
    }

    public function testATimeALocalDateTimeAndADurationCompareToo(): void
    {
        self::assertTrue(self::takes('{:refine :time %{min: @time "09:00:00"}}', '@time "09:30:00"'));
        self::assertTrue(self::takes(
            '{:refine :local-datetime %{max: @local-datetime "2026-10-20T00:00:00"}}',
            '@local-datetime "2026-10-19T23:59:59.999999999"',
        ));
        self::assertTrue(self::takes('{:refine :duration %{max: @duration "PT1H"}}', '@duration "PT59M"'));
        self::assertFalse(self::takes('{:refine :duration %{max: @duration "PT1H"}}', '@duration "P1D"'));
    }

    public function testADurationWithYearsOrMonthsOrValuesOfTwoKindsCompareWithNothing(): void
    {
        self::assertFalse(self::takes('{:refine :duration %{max: @duration "P400D"}}', '@duration "P1Y"'));
        self::assertFalse(self::takes('{:refine :any %{min: @date "2026-01-01"}}', '@time "12:00:00"'));
        self::assertFalse(self::takes('{:refine :any %{min: @date "2026-01-01"}}', '5'));
    }

    /**
     * The reference's far future is 2999-01-01; a datetime here is
     * nanoseconds since 1970 in 64 bits, which end in 2262, so the far
     * future is 2200-01-01.
     */
    public function testBeforeNowAfterNowAndWithinCompareADatetimeWithTheTimeOfTheCheck(): void
    {
        self::assertTrue(self::takes('{:refine :datetime %{before-now: true}}', '@datetime "2000-01-01T00:00:00Z"'));
        self::assertFalse(self::takes('{:refine :datetime %{after-now: true}}', '@datetime "2000-01-01T00:00:00Z"'));
        self::assertTrue(self::takes('{:refine :datetime %{after-now: true}}', '@datetime "2200-01-01T00:00:00Z"'));
        self::assertFalse(self::takes('{:refine :datetime %{within: @duration "PT1H"}}', '@datetime "2000-01-01T00:00:00Z"'));

        $registry = self::compiled('{:refine :datetime %{within: @duration "PT1H"}}');
        self::assertTrue(Schema::check(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $registry, 'T'));
    }

    public function testResolutionNoPartOfTheValueIsFinerThanTheUnitGiven(): void
    {
        self::assertTrue(self::takes('{:refine :time %{resolution: :second}}', '@time "12:00:00"'));
        self::assertFalse(self::takes('{:refine :time %{resolution: :second}}', '@time "12:00:00.5"'));
        self::assertTrue(self::takes('{:refine :datetime %{resolution: :millisecond}}', '@datetime "2026-10-20T12:00:00.123Z"'));
        self::assertFalse(self::takes('{:refine :datetime %{resolution: :millisecond}}', '@datetime "2026-10-20T12:00:00.1234Z"'));
        self::assertTrue(self::takes('{:refine :duration %{resolution: :second}}', '@duration "PT2S"'));
        self::assertFalse(self::takes('{:refine :duration %{resolution: :second}}', '@duration "PT2.5S"'));
    }
}
