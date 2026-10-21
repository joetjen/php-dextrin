<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Value\DXNKeyword;
use PHPUnit\Framework\TestCase;

/**
 * The canonical form of DXN.md §1.5: one rendering fixed down to the
 * byte, which is what the Dextrin Conformance Suite compares across
 * implementations. Most cases decode text and print what it came to,
 * which is exactly what this implementation answers the suite with.
 */
final class CanonicalTest extends TestCase
{
    private static function canonicalOf(string $text): string
    {
        return Canonical::print(Dextrin::decode($text));
    }

    public function testScalarsAreThemselves(): void
    {
        self::assertSame('nil', self::canonicalOf('nil'));
        self::assertSame('true', self::canonicalOf('true'));
        self::assertSame('-7', self::canonicalOf('-7'));
        self::assertSame('123456789012345678901234567890', self::canonicalOf('123456789012345678901234567890'));
    }

    public function testADecimalIsWrittenAsItWasGiven(): void
    {
        self::assertSame('1.50M', self::canonicalOf('1.50M'));
        self::assertSame('-0.0M', self::canonicalOf('-0.0M'));
    }

    public function testARationalIsNotReduced(): void
    {
        self::assertSame('2/4', self::canonicalOf('2/4'));
    }

    public function testAFloatKeepsItsDotZeroAndIsWrittenOutWithinTheThresholds(): void
    {
        self::assertSame('1.0', self::canonicalOf('1.0'));
        self::assertSame('100.0', self::canonicalOf('100.0'));
        self::assertSame('0.1', self::canonicalOf('0.1'));
        self::assertSame('0.000001', self::canonicalOf('0.000001'));
        self::assertSame('123456789012345680000.0', self::canonicalOf('123456789012345680000.0'));
        self::assertSame('-0.0', self::canonicalOf('-0.0'));
    }

    public function testAFloatBeyondThemGoesToAnExponentWithoutAPlus(): void
    {
        self::assertSame('1.5e-7', self::canonicalOf('0.00000015'));
        self::assertSame('1.0e21', self::canonicalOf('1e21'));
        self::assertSame('2.5e300', self::canonicalOf('2.5E+300'));
    }

    public function testNaNAndTheInfinitiesAreWords(): void
    {
        self::assertSame('NaN', self::canonicalOf('NaN'));
        self::assertSame('-Infinity', self::canonicalOf('-Infinity'));
    }

    public function testAStringEscapesWhatItMustInLowercaseHex(): void
    {
        self::assertSame('"a\\"b\\\\c"', self::canonicalOf('"a\\"b\\\\c"'));
        self::assertSame('"a\\tb\\x{1b}"', self::canonicalOf('"a\\tb\\x{1B}"'));
        self::assertSame('"\\x{7f}"', self::canonicalOf('"\\x{7f}"'));
        self::assertSame('"é"', self::canonicalOf('"\\x{e9}"'));
    }

    public function testACharAndASpaceChar(): void
    {
        self::assertSame('?a', self::canonicalOf('?a'));
        self::assertSame('?s', self::canonicalOf('?s'));
    }

    public function testAKeywordIsBareWhenItsNameIsAnIdentifier(): void
    {
        self::assertSame(':kw', Canonical::print(new DXNKeyword('kw')));
        self::assertSame(':"two words"', Canonical::print(new DXNKeyword('two words')));
    }

    public function testCollectionsAreOneSpaceApart(): void
    {
        self::assertSame('[1 2 3]', self::canonicalOf('[1, 2,  3]'));
        self::assertSame('{1 2}', self::canonicalOf('{1 2}'));
        self::assertSame('@array[1 2]', self::canonicalOf('@array[1 2]'));
    }

    public function testAMapIsInCanonicalKeyOrderWithShorthandForNames(): void
    {
        self::assertSame('%{"z" => 3 a: 1 b: 2}', self::canonicalOf('%{b: 2, a: 1, "z" => 3}'));
    }

    public function testAnOrderedMapKeepsItsOrderAndASetIsSorted(): void
    {
        self::assertSame('@ordered %{b: 2 a: 1}', self::canonicalOf('@ordered %{b: 2, a: 1}'));
        self::assertSame('@{1 2 3}', self::canonicalOf('@{3 1 2}'));
    }

    public function testAKeyedStructKeepsItsEntriesInOrder(): void
    {
        self::assertSame('%P{y: 1 x: 2}', self::canonicalOf('%P{y: 1, x: 2}'));
        self::assertSame('%P[1 2]', self::canonicalOf('%P[1 2]'));
    }

    public function testDatesAndTimes(): void
    {
        self::assertSame('@date "2026-10-17"', self::canonicalOf('@date "2026-10-17"'));
        self::assertSame('@time "10:00:00.5"', self::canonicalOf('@time "10:00:00.500000"'));
        self::assertSame('@datetime "2026-10-17T10:00:00Z"', self::canonicalOf('@datetime "2026-10-17T10:00:00Z"'));
        self::assertSame('@datetime "2026-10-17T10:00:00.25Z"', self::canonicalOf('@datetime "2026-10-17T10:00:00.250000+00:00"'));
        self::assertSame(
            '@datetime "2026-10-17T10:00:00+02:00"',
            self::canonicalOf('@datetime "2026-10-17T10:00:00+02:00"'),
        );
    }

    public function testAUuidIsLowercaseAndBytesArePaddedBase64(): void
    {
        self::assertSame(
            '@uuid "6f9619ff-8b86-d011-b42d-00c04fc964ff"',
            self::canonicalOf('@uuid "6F9619FF-8B86-D011-B42D-00C04FC964FF"'),
        );
        self::assertSame('@bytes "AAE="', self::canonicalOf('@bytes "AAE="'));
    }

    public function testAValueWithNoDxnMeaningIsAnEncodeError(): void
    {
        try {
            Canonical::print(static fn () => 1);
            self::fail('expected a DXNError');
        } catch (DXNError $err) {
            self::assertSame('encode/unsupported', $err->category);
        }
    }

    /**
     * The shortest digits that read back, even on a power of two, where the
     * gap below the value is half the gap above it (2^-24: sixteen digits,
     * rounding to the nearest sixteen gives ...062, which does not read
     * back; ...063 does).
     */
    public function testAFloatOnABinaryBoundaryIsWrittenInItsShortestDigits(): void
    {
        $this->assertSame('5.960464477539063e-8', Canonical::float(2 ** -24));
        $this->assertSame('0.1', Canonical::float(0.1));
        $this->assertSame('5.0e-324', Canonical::float(5e-324));
        $this->assertSame('1.7976931348623157e308', Canonical::float(1.7976931348623157e308));
    }
}
