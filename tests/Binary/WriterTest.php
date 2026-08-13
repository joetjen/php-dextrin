<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Binary;

use JOetjen\Dextrin\Binary\Writer;
use JOetjen\Dextrin\Value\DXNInteger;
use PHPUnit\Framework\TestCase;

/**
 * Low-level header/byte-boundary tests -- this is exactly the class
 * of bug the project's own plan flagged in advance (mirroring the JS
 * port's `writeBigUInt64BE` header-byte-offset bug): PHP's `int` is
 * signed 64-bit, so CBOR's own unsigned 64-bit argument range
 * (`2^64-1`) genuinely exceeds it. These tests exist specifically to
 * catch that class of bug at the byte level, not just via a
 * higher-level round-trip that might not exercise values anywhere
 * near the boundary.
 */
final class WriterTest extends TestCase
{
    public function testHeadShortestFormBoundaries(): void
    {
        self::assertSame("\x00", Writer::head(0, '0'));
        self::assertSame("\x17", Writer::head(0, '23'));
        self::assertSame("\x18\x18", Writer::head(0, '24'));
        self::assertSame("\x18\xff", Writer::head(0, '255'));
        self::assertSame("\x19\x01\x00", Writer::head(0, '256'));
        self::assertSame("\x19\xff\xff", Writer::head(0, '65535'));
        self::assertSame("\x1a\x00\x01\x00\x00", Writer::head(0, '65536'));
        self::assertSame("\x1a\xff\xff\xff\xff", Writer::head(0, '4294967295'));
        self::assertSame("\x1b\x00\x00\x00\x01\x00\x00\x00\x00", Writer::head(0, '4294967296'));
    }

    public function testHeadCrossingPhpIntMaxDoesNotCorruptTheHeaderByte(): void
    {
        // The exact boundary this test suite exists to catch: PHP_INT_MAX
        // is 2^63-1, well inside CBOR's valid 2^64-1 argument range --
        // crossing it must never touch the header byte's own bits.
        $atMax = Writer::head(0, (string) PHP_INT_MAX);
        self::assertSame("\x1b\x7f\xff\xff\xff\xff\xff\xff\xff", $atMax);

        $overMax = Writer::head(0, bcadd((string) PHP_INT_MAX, '1', 0));
        self::assertSame("\x1b\x80\x00\x00\x00\x00\x00\x00\x00", $overMax);
        self::assertSame("\x1b", $overMax[0], 'header byte must stay major=0, info=27');
    }

    public function testHeadAtCborUint64Max(): void
    {
        $bytes = Writer::head(0, '18446744073709551615');
        self::assertSame(9, strlen($bytes));
        self::assertSame("\x1b\xff\xff\xff\xff\xff\xff\xff\xff", $bytes);
    }

    public function testHeadRejectsValuesBeyondCborRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Writer::head(0, '18446744073709551616'); // 2^64
    }

    public function testHeadEncodesMajorTypeCorrectly(): void
    {
        self::assertSame("\x20", Writer::head(1, '0')); // major 1, arg 0
        self::assertSame("\x60", Writer::head(3, '0')); // major 3 (text), arg 0
        self::assertSame("\xc0", Writer::head(6, '0')); // major 6 (tag), arg 0
    }

    public function testFloatHeadIsExactlyOneByte(): void
    {
        self::assertSame("\xfb", Writer::floatHead());
    }

    public function testFloatItemEncodesBigEndianDouble(): void
    {
        $item = Writer::floatItem(1.0);
        self::assertSame(9, strlen($item));
        self::assertSame("\xfb\x3f\xf0\x00\x00\x00\x00\x00\x00", $item);
    }

    public function testBigDigitsToBytesAndBackRoundTripsArbitraryMagnitude(): void
    {
        foreach (['0', '1', '255', '256', '65535', '99999999999999999999999999999999999999'] as $n) {
            $bytes = Writer::bigDigitsToBytes($n);
            self::assertSame($n, Writer::bytesToBigDigits($bytes));
        }
    }

    public function testBigDigitsToBytesHasNoLeadingZeroExceptForZeroItself(): void
    {
        self::assertSame("\x00", Writer::bigDigitsToBytes('0'));
        self::assertSame("\x01", Writer::bigDigitsToBytes('1'));
        self::assertSame("\x01\x00", Writer::bigDigitsToBytes('256'));
    }

    public function testIntegerItemSmallPositiveAndNegative(): void
    {
        self::assertSame("\x00", Writer::integerItem(0));
        self::assertSame("\x18\x2a", Writer::integerItem(42));
        self::assertSame("\x20", Writer::integerItem(-1));
        self::assertSame("\x38\x63", Writer::integerItem(-100)); // -1-(-100) = 99 = 0x63
    }

    public function testIntegerItemUsesNativeHeadUpToUint64Max(): void
    {
        $item = Writer::integerItem((int) PHP_INT_MAX);
        self::assertSame("\x1b\x7f\xff\xff\xff\xff\xff\xff\xff", $item);
    }

    public function testIntegerItemUsesBignumTagBeyondUint64Max(): void
    {
        $huge = DXNInteger::fromString('99999999999999999999999999999');
        $item = Writer::integerItem($huge);
        // tag 2 (positive bignum): head(6, 2) = 0xc2
        self::assertSame("\xc2", $item[0]);
    }

    public function testIntegerItemUsesNegativeBignumTagForHugeNegatives(): void
    {
        $huge = DXNInteger::fromString('-99999999999999999999999999999');
        $item = Writer::integerItem($huge);
        // tag 3 (negative bignum): head(6, 3) = 0xc3
        self::assertSame("\xc3", $item[0]);
    }

    public function testArrayOfConcatenatesItemsWithCountHeader(): void
    {
        $result = Writer::arrayOf([Writer::integerItem(1), Writer::integerItem(2)]);
        self::assertSame("\x82\x01\x02", $result); // major4 count2, then 1, then 2
    }

    public function testMapOfConcatenatesPairsWithCountHeader(): void
    {
        $result = Writer::mapOf([[Writer::integerItem(1), Writer::integerItem(2)]]);
        self::assertSame("\xa1\x01\x02", $result); // major5 count1, key 1, value 2
    }

    public function testTextItemUsesUtf8ByteLength(): void
    {
        // "é" is 1 codepoint but 2 UTF-8 bytes -- the header must count bytes, not codepoints.
        $result = Writer::textItem('é');
        self::assertSame("\x62\xc3\xa9", $result);
    }

    public function testBytesItemUsesRawByteLength(): void
    {
        $result = Writer::bytesItem("\x01\x02\x03");
        self::assertSame("\x43\x01\x02\x03", $result);
    }
}
