<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNLocalDateTime;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNTime;
use PHPUnit\Framework\TestCase;

/**
 * One way to write a value (Jan, 2026-10-20; DXN.md §1.2): dates, times
 * and regexes are tags with a string, sigils are gone, timestamp and
 * datetime are one type, and times carry nanoseconds -- in text and in
 * `.dxnb`, where 0.2's microsecond tags are still read as they were meant.
 */
final class OneSpellingTest extends TestCase
{
    private static function category(string $text): ?string
    {
        try {
            Dextrin::decode($text);
        } catch (DXNError $err) {
            return $err->category;
        }
        return null;
    }

    private static function roundTrip(string $text): string
    {
        return Canonical::print(Dextrin::decodeBinary(Dextrin::encodeBinary(Dextrin::decode($text))));
    }

    // ---- sigils are gone -------------------------------------------------------

    public function testEverySigilIsRefused(): void
    {
        foreach (['~D[2026-10-20]', '~T[12:00:00]', '~U[2026-10-20 12:00:00Z]', '~r/a/i'] as $old) {
            self::assertSame('syntax', self::category($old), $old);
        }
    }

    // ---- dates and times -------------------------------------------------------

    public function testDatesAndTimesReadAndWriteAsIso8601StringsUnderTheirTag(): void
    {
        foreach ([
            '@date "2026-10-20"',
            '@time "14:30:00.5"',
            '@datetime "2026-10-20T14:30:00Z"',
            '@datetime "2026-10-20T16:30:00+02:00"',
            '@local-datetime "2026-10-20T14:30:00"',
            '@duration "P1DT2.5S"',
        ] as $text) {
            self::assertSame($text, Canonical::print(Dextrin::decode($text)), $text);
        }
    }

    public function testAUtcDatetimeIsWrittenZWhicheverWayItWasGiven(): void
    {
        self::assertSame(
            '@datetime "2026-10-20T14:30:00Z"',
            Canonical::print(Dextrin::decode('@datetime "2026-10-20T14:30:00+00:00"')),
        );
    }

    public function testAnUnknownOffsetIsRefusedAndALocalDateTimeHasNone(): void
    {
        self::assertSame('syntax', self::category('@datetime "2026-10-20T14:30:00-00:00"'));
        self::assertSame('syntax', self::category('@datetime "2026-10-20T14:30:00"'));
        self::assertSame('syntax', self::category('@local-datetime "2026-10-20T14:30:00Z"'));
    }

    public function testNineFractionDigitsSurviveTextAndBinary(): void
    {
        foreach ([
            '@time "14:30:00.123456789"',
            '@datetime "2026-10-20T14:30:00.000000001+05:30"',
            '@local-datetime "1969-12-31T23:59:59.999999999"',
            '@duration "PT0.000000001S"',
        ] as $text) {
            self::assertSame($text, self::roundTrip($text), $text);
        }
    }

    // The reference's binary round-trip cases for these types.
    public function testEveryDateTimeAndRegexFormRoundTripsThroughBinary(): void
    {
        foreach ([
            '@date "1990-01-01"',
            '@time "12:00:00"',
            '@time "12:00:00.123456"',
            '@time "12:00:00.123456789"',
            '@local-datetime "1990-01-01T12:00:00.5"',
            '@datetime "1990-01-01T12:00:00.000000001+02:00"',
            '@datetime "1990-01-01T00:00:00Z"',
            '@regex(imsuxfr) "^a.b$"',
            '@regex "(?i)^a.b$"',
        ] as $text) {
            self::assertSame($text, self::roundTrip($text), $text);
        }
    }

    // The reference holds a value finer than a microsecond in a
    // `Dextrin.Nanos`, Elixir's own types stopping at the microsecond;
    // here every time holds nanoseconds, so there is one class for each.
    public function testATimeHoldsItsNanoseconds(): void
    {
        $time = Dextrin::decode('@time "14:30:00.123456789"');
        self::assertInstanceOf(DXNTime::class, $time);
        self::assertSame(123_456_789, $time->nanosecond);
        self::assertInstanceOf(DXNLocalDateTime::class, Dextrin::decode('@local-datetime "2026-10-20T14:30:00"'));
    }

    // ---- regex -----------------------------------------------------------------

    public function testFlagsInAHeaderOrInlineInThePatternAreTwoDifferentValues(): void
    {
        self::assertSame('@regex(im) "^\\\\d+$"', self::roundTrip('@regex(im) "^\\\\d+$"'));
        self::assertSame('@regex "(?im)^\\\\d+$"', self::roundTrip('@regex "(?im)^\\\\d+$"'));

        $header = Dextrin::decode('@regex(i) "a"');
        $inline = Dextrin::decode('@regex "(?i)a"');
        self::assertInstanceOf(DXNRegex::class, $header);
        self::assertInstanceOf(DXNRegex::class, $inline);
        self::assertFalse($header->equals($inline));
        self::assertSame(1, preg_match($header->toPcrePattern(), 'A'));
        self::assertSame(1, preg_match($inline->toPcrePattern(), 'A'));
    }

    public function testTheFlagsAreWrittenInTheirCanonicalOrder(): void
    {
        self::assertSame('@regex(im) "a"', Canonical::print(Dextrin::decode('@regex(mi) "a"')));
    }

    public function testAHeaderOnAnyOtherTagAFlagGivenTwiceOrAnUnknownFlagIsRefused(): void
    {
        self::assertSame('syntax', self::category('@uuid(i) "x"'));
        self::assertSame('syntax', self::category('@regex(ii) "a"'));
        self::assertSame('syntax', self::category('@regex(q) "a"'));
    }

    public function testAPatternThatIsNoRegexIsRefused(): void
    {
        self::assertSame('syntax', self::category('@regex "("'));
    }

    // ---- 0.2's binary ------------------------------------------------------------

    // A tag never changes its meaning (DXN.md §2.3): 0.2 wrote a time as
    // microseconds under 210, a UTC timestamp under 1.
    public function testAMicrosecondTimeAndATag1TimestampReadAsWhatTheyMeant(): void
    {
        // 210(43_200_000_000): 12:00:00 in microseconds.
        $time = "DX\x01\xD8\xD2\x1B" . pack('J', 43_200_000_000);
        self::assertEquals(new DXNTime(12, 0, 0), Dextrin::decodeBinary($time));

        // 1(1_000_000): one second past the epoch, in microseconds.
        $utc = "DX\x01\xC1\x1A" . pack('N', 1_000_000);
        self::assertEquals(new DXNDateTime(1_000_000_000, 0), Dextrin::decodeBinary($utc));
    }

    public function testA02DatetimeAndDurationReadAsWhatTheyMeant(): void
    {
        // 211([1_000_000, 120]): one second past the epoch, at +02:00.
        $datetime = "DX\x01\xD8\xD3\x82\x1A" . pack('N', 1_000_000) . "\x18\x78";
        self::assertSame('@datetime "1970-01-01T02:00:01+02:00"', Canonical::print(Dextrin::decodeBinary($datetime)));

        // 212([0b1000000, 1_500_000]): 1.5 seconds, in microseconds.
        $duration = "DX\x01\xD8\xD4\x82\x18\x40\x1A" . pack('N', 1_500_000);
        self::assertSame('@duration "PT1.5S"', Canonical::print(Dextrin::decodeBinary($duration)));
    }

    // PHP's integers are 64 bits: epoch nanoseconds reach from 1677 to
    // 2262, and an instant outside that is refused, not wrapped.
    public function testADatetimeOutsideWhatNanosecondsSinceTheEpochHoldIsRefused(): void
    {
        self::assertSame('syntax', self::category('@datetime "2300-01-01T00:00:00Z"'));
    }
}
