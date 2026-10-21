<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNBits;
use JOetjen\Dextrin\Value\DXNBytes;
use PHPUnit\Framework\TestCase;

/**
 * `bits` (DXN.md §1.3, §1.4, §2.3): a run of bits that is not whole
 * bytes -- `@bits {"oA==" 3}` in text, `T_BITS` (216) around
 * `[bytes, count]` in `.dxnb`. Bits and bytes are one value space: a
 * whole number of bytes is bytes. PHP has no bitstring, so DXNBits holds
 * the bytes and the count.
 */
final class BitsTest extends TestCase
{
    private static function category(\Closure $refused): ?string
    {
        try {
            $refused();
        } catch (DXNError $err) {
            return $err->category;
        }
        self::fail('expected a refusal');
    }

    public function testTextReadsARunOfBitsFromTheHighBitOfTheFirstByte(): void
    {
        $value = Dextrin::decode('@bits {"oA==" 3}');
        self::assertInstanceOf(DXNBits::class, $value);
        self::assertSame([3, 'a0'], [$value->bitLength, bin2hex($value->data)]);
    }

    public function testAWholeNumberOfBytesIsBytes(): void
    {
        $value = Dextrin::decode('@bits {"AQID" 24}');
        self::assertInstanceOf(DXNBytes::class, $value);
        self::assertSame('010203', bin2hex($value->data));
    }

    public function testTheUnusedLowBitsOfTheLastByteAreIgnored(): void
    {
        self::assertTrue(Dextrin::decode('@bits {"pw==" 3}')->equals(Dextrin::decode('@bits {"oA==" 3}')));
    }

    public function testACountTheDataDoesNotMatchIsRefused(): void
    {
        foreach (['@bits {"oA==" 9}', '@bits {"AAA=" 3}', '@bits {"oA==" -1}', '@bits "oA=="'] as $text) {
            self::assertSame('syntax', self::category(static fn () => Dextrin::decode($text)), $text);
        }
    }

    public function testItIsWrittenWithTheCountTheUnusedBitsZero(): void
    {
        $bits = DXNBits::from("\xa7", 3);
        self::assertSame('@bits {"oA==" 3}', Dextrin::encode($bits));
        self::assertSame('@bits {"oA==" 3}', Dextrin::encode($bits, pretty: true));
        self::assertSame('@bits {"oA==" 3}', Canonical::print($bits));
    }

    public function testInBinaryItIsTBitsAroundTheBytesAndTheCount(): void
    {
        self::assertSame('445801d8d88241a003', bin2hex(Dextrin::encodeBinary(DXNBits::from("\xa0", 3))));
        self::assertTrue(Dextrin::decodeBinary(Dextrin::encodeBinary(DXNBits::from("\x01\xa0", 11)))->equals(DXNBits::from("\x01\xa0", 11)));
    }

    public function testInBinaryACountItsBytesDoNotMatchIsMalformed(): void
    {
        self::assertSame('binary', self::category(static fn () => Dextrin::decodeBinary(hex2bin('445801d8d88241a009'))));
        self::assertSame('binary', self::category(static fn () => Dextrin::decodeBinary(hex2bin('445801d8d86161'))));
    }

    public function testBitsAreNoMapKey(): void
    {
        self::assertSame('key/not-allowed', self::category(static fn () => Dextrin::decode('%{@bits {"oA==" 3} => 1}')));
    }

    public function testASchemasBitsTakesBitsAndBytesItsBytesOnlyWholeBytes(): void
    {
        $registry = static fn (string $type): Registry => Schema::compile(Dextrin::decode("%{S: %schema{b: {$type}}}"), new Registry());
        Dextrin::decode('%S{b: @bits {"oA==" 3}}', $registry(':bits'));
        Dextrin::decode('%S{b: @bytes "AQ=="}', $registry(':bits'));
        self::assertSame('schema/type-mismatch', self::category(static fn () => Dextrin::decode('%S{b: @bits {"oA==" 3}}', $registry(':bytes'))));
    }
}
