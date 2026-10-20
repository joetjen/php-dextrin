<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Binary;

use JOetjen\Dextrin\Dextrin;
use PHPUnit\Framework\TestCase;

/**
 * `.dxnb` writes the shortest CBOR width that holds a float exactly, and
 * reads every width (DXN.md §2.2, Jan, 2026-10-20) -- the smallest
 * representation, which also writes a UTC datetime as its bare
 * nanoseconds and a regex without flags as its bare pattern.
 */
final class CborFloatInteropTest extends TestCase
{
    /** A float's IEEE 754 double bits, so -0.0 and 0.0 differ. */
    private static function bits(float $f): string
    {
        return bin2hex(pack('E', $f));
    }

    private static function half(int $bits): mixed
    {
        return Dextrin::decodeBinary("DX\x01\xf9" . pack('n', $bits));
    }

    /** The width `$f` is written in: half, single or double. */
    private static function width(float $f): string
    {
        $bytes = Dextrin::encodeBinary($f);
        return [0xf9 => 'half', 0xfa => 'single', 0xfb => 'double'][ord($bytes[3])] ?? 'none';
    }

    public function testDecodesAStandardDoublePrecisionFloatHeader(): void
    {
        self::assertSame(19.99, Dextrin::decodeBinary("DX\x01\xfb" . pack('E', 19.99)));
    }

    public function testAFloatHalfAndSingleCannotHoldIsWrittenAsADouble(): void
    {
        $bytes = Dextrin::encodeBinary(19.99);
        self::assertSame("DX\x01\xfb", substr($bytes, 0, 4));
        self::assertSame(12, strlen($bytes));
    }

    public function testDecodesASinglePrecisionFloat(): void
    {
        self::assertSame(1.5, Dextrin::decodeBinary("DX\x01\xfa" . pack('G', 1.5)));
    }

    public function testHalfPrecisionFloatsAreReadSpecialsAndSubnormalsIncluded(): void
    {
        self::assertSame(self::bits(0.0), self::bits(self::float(self::half(0x0000))));
        self::assertSame(self::bits(-0.0), self::bits(self::float(self::half(0x8000))));
        self::assertSame(-2.0, self::half(0xC000));
        self::assertSame(65_504.0, self::half(0x7BFF));
        self::assertSame(5.960464477539063e-8, self::half(0x0001));
        self::assertSame(INF, self::half(0x7C00));
        self::assertSame(-INF, self::half(0xFC00));
        self::assertNan(self::float(self::half(0x7E00)));
    }

    public function testSinglePrecisionSpecialsAndSubnormalsAreRead(): void
    {
        self::assertSame(INF, Dextrin::decodeBinary("DX\x01\xfa\x7f\x80\x00\x00"));
        self::assertSame(-INF, Dextrin::decodeBinary("DX\x01\xfa\xff\x80\x00\x00"));
        self::assertNan(self::float(Dextrin::decodeBinary("DX\x01\xfa\x7f\xc0\x00\x00")));
        self::assertSame(2 ** -149, Dextrin::decodeBinary("DX\x01\xfa\x00\x00\x00\x01"));
    }

    public function testAFloatIsWrittenInTheShortestWidthThatHoldsItExactly(): void
    {
        self::assertSame('half', self::width(1.5));
        self::assertSame('half', self::width(-0.0));
        self::assertSame('half', self::width(65_504.0));
        self::assertSame('half', self::width(5.960464477539063e-8));
        self::assertSame('single', self::width(65_520.0));
        self::assertSame('single', self::width(2 ** -149));
        self::assertSame('double', self::width(0.1));
        self::assertSame('double', self::width(3.141592653589793));
        self::assertSame('half', self::width(NAN));
        self::assertSame('half', self::width(INF));
        self::assertSame('half', self::width(-INF));

        self::assertSame("DX\x01\xf9\x80\x00", Dextrin::encodeBinary(-0.0));
        self::assertSame("DX\x01\xf9\x7e\x00", Dextrin::encodeBinary(NAN));
        self::assertSame("DX\x01\xf9\x7c\x00", Dextrin::encodeBinary(INF));
        self::assertSame("DX\x01\xf9\xfc\x00", Dextrin::encodeBinary(-INF));

        foreach ([1.5, -0.0, 65_520.0, 0.1, 1.0e-40, 1.0e300, 2 ** -149, -INF, INF] as $f) {
            $back = self::float(Dextrin::decodeBinary(Dextrin::encodeBinary($f)));
            self::assertSame(self::bits($f), self::bits($back), (string) $f);
        }
        self::assertNan(self::float(Dextrin::decodeBinary(Dextrin::encodeBinary(NAN))));
    }

    public function testAUtcDatetimeIsItsBareNanosecondsARegexWithoutFlagsItsBarePattern(): void
    {
        // Tag 219, then the integer 1_000_000_000: no array, no offset.
        self::assertSame(
            "DX\x01\xd8\xdb\x1a" . pack('N', 1_000_000_000),
            Dextrin::encodeBinary(Dextrin::decode('@datetime "1970-01-01T00:00:01Z"')),
        );
        // Tag 213, then the string "a".
        self::assertSame("DX\x01\xd8\xd5\x61a", Dextrin::encodeBinary(Dextrin::decode('@regex "a"')));
    }

    public function testADatetimeWithAnOffsetAndARegexWithFlagsKeepTheirArrays(): void
    {
        // Tag 219, [1_000_000_000, 120].
        self::assertSame(
            "DX\x01\xd8\xdb\x82\x1a" . pack('N', 1_000_000_000) . "\x18\x78",
            Dextrin::encodeBinary(Dextrin::decode('@datetime "1970-01-01T02:00:01+02:00"')),
        );
        // Tag 213, ["a", 1]: the i flag.
        self::assertSame("DX\x01\xd8\xd5\x82\x61a\x01", Dextrin::encodeBinary(Dextrin::decode('@regex(i) "a"')));
    }

    public function testAReaderReadsTheOldFormsToo(): void
    {
        // [nanoseconds, 0] and ["a", 0], as the writer wrote them before.
        self::assertEquals(
            Dextrin::decode('@datetime "1970-01-01T00:00:01Z"'),
            Dextrin::decodeBinary("DX\x01\xd8\xdb\x82\x1a" . pack('N', 1_000_000_000) . "\x00"),
        );
        self::assertEquals(
            Dextrin::decode('@regex "a"'),
            Dextrin::decodeBinary("DX\x01\xd8\xd5\x82\x61a\x00"),
        );
        foreach (['@datetime "1970-01-01T00:00:01Z"', '@regex "a"'] as $text) {
            $value = Dextrin::decode($text);
            self::assertEquals($value, Dextrin::decodeBinary(Dextrin::encodeBinary($value)), $text);
        }
    }

    public function testSpecialFloatValuesStillRoundTrip(): void
    {
        foreach ([INF, -INF, -0.0, 1.0e10] as $f) {
            self::assertSame(self::bits($f), self::bits(self::float(Dextrin::decodeBinary(Dextrin::encodeBinary($f)))));
        }
    }

    private static function float(mixed $value): float
    {
        self::assertIsFloat($value);
        return $value;
    }
}
