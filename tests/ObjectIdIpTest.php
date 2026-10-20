<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNIp;
use JOetjen\Dextrin\Value\DXNMap;
use JOetjen\Dextrin\Value\DXNObjectId;
use PHPUnit\Framework\TestCase;

/**
 * `@objectid` and `@ip`, built in like `@uuid` (Jan, 2026-10-20; DXN.md
 * §1.2): readable in text, their raw bytes in `.dxnb`, the smallest form
 * that keeps them.
 */
final class ObjectIdIpTest extends TestCase
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

    private static function canonical(string $text): string
    {
        return Canonical::print(Dextrin::decode($text));
    }

    /** The `.dxnb` item `$text` encodes to, without its envelope. */
    private static function binary(string $text): string
    {
        $bytes = Dextrin::encodeBinary(Dextrin::decode($text));
        self::assertSame("DX\x01", substr($bytes, 0, 3));
        return substr($bytes, 3);
    }

    // ---- the value classes ------------------------------------------------------

    public function testAnObjectIdReadsHexInEitherCaseAndWritesItLowercase(): void
    {
        $id = DXNObjectId::fromString('507F1F77BCF86CD799439011');
        self::assertSame('507f1f77bcf86cd799439011', $id->toString());
        self::assertSame(12, strlen($id->bytes));
        self::assertSame('objectid', $id->dxnType());
        self::assertTrue($id->equals(DXNObjectId::fromString('507f1f77bcf86cd799439011')));
    }

    public function testAnObjectIdIsTwelveBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNObjectId(str_repeat("\x00", 11));
    }

    public function testAnIpPrefixIsWrittenAsRfc5952Says(): void
    {
        $ip = DXNIp::fromString('2001:DB8:0:0::/32');
        self::assertSame('2001:db8::/32', $ip->toString());
        self::assertSame(6, $ip->version());
        self::assertSame(32, $ip->prefix);
        self::assertSame(4, DXNIp::fromString('192.0.2.1')->version());
        self::assertNull(DXNIp::fromString('192.0.2.1')->prefix);
        self::assertSame('ip', $ip->dxnType());
    }

    public function testAnIpBuiltFromBytesIsCheckedToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNIp("\x0a\x00\x00\x01", 8);
    }

    // ---- @objectid --------------------------------------------------------------

    public function testObjectIdIs24HexDigitsEitherCaseReadLowercaseWritten(): void
    {
        self::assertSame(
            '@objectid "507f1f77bcf86cd799439011"',
            self::canonical('@objectid "507F1F77BCF86CD799439011"'),
        );
        self::assertSame(
            '@objectid "507f1f77bcf86cd799439011"',
            Dextrin::encode(Dextrin::decode('@objectid "507F1F77BCF86CD799439011"')),
        );
    }

    public function testObjectIdIs12RawBytesInDxnbUnderTag221(): void
    {
        // 0xD8 0xDD: tag 221; 0x4C: a 12-byte byte string.
        $item = self::binary('@objectid "507f1f77bcf86cd799439011"');
        self::assertSame("\xd8\xdd\x4c", substr($item, 0, 3));
        self::assertSame('507f1f77bcf86cd799439011', bin2hex(substr($item, 3)));
        self::assertSame(15, strlen($item));

        $back = Dextrin::decodeBinary("DX\x01" . $item);
        self::assertInstanceOf(DXNObjectId::class, $back);
        self::assertSame('507f1f77bcf86cd799439011', $back->toString());
    }

    public function testAnythingButTwelveBytesUnderTag221IsMalformed(): void
    {
        try {
            Dextrin::decodeBinary("DX\x01\xd8\xdd\x4b" . str_repeat("\x00", 11));
            self::fail('an 11-byte objectid decoded');
        } catch (DXNError $err) {
            self::assertSame('binary', $err->category);
        }
    }

    public function testAnythingButTwentyFourHexDigitsIsRefused(): void
    {
        foreach (['507f1f77bcf86cd79943901', '507f1f77bcf86cd79943901g', ''] as $bad) {
            self::assertSame('syntax', self::category("@objectid \"{$bad}\""), $bad);
        }
    }

    public function testObjectIdMayBeAMapKey(): void
    {
        $map = Dextrin::decode('%{@objectid "507f1f77bcf86cd799439011" => 1}');
        self::assertInstanceOf(DXNMap::class, $map);
        self::assertSame(1, $map->get(DXNObjectId::fromString('507f1f77bcf86cd799439011')));
        self::assertEquals($map, Dextrin::decodeBinary(Dextrin::encodeBinary($map)));
    }

    // ---- @ip --------------------------------------------------------------------

    public function testAnAddressOrAPrefixIpv4OrIpv6InTheirCanonicalText(): void
    {
        self::assertSame('@ip "192.0.2.1"', self::canonical('@ip "192.0.2.1"'));
        self::assertSame('@ip "10.0.0.0/8"', self::canonical('@ip "10.0.0.0/8"'));
        self::assertSame('@ip "2001:db8::1"', self::canonical('@ip "2001:DB8:0:0:0:0:0:1"'));
        self::assertSame('@ip "2001:db8::/32"', self::canonical('@ip "2001:db8::/32"'));
        self::assertSame('@ip "2001:db8::1"', Dextrin::encode(Dextrin::decode('@ip "2001:DB8:0:0:0:0:0:1"')));
    }

    // As the reference reads and writes them, whatever the platform's
    // inet_pton() and inet_ntop() would say.
    public function testAnIpv4TailIsWrittenDottedAsTheReferenceWritesIt(): void
    {
        self::assertSame('@ip "::ffff:192.0.2.1"', self::canonical('@ip "::FFFF:C000:201"'));
        self::assertSame('@ip "::0.0.0.2"', self::canonical('@ip "::2"'));
        self::assertSame('@ip "::1"', self::canonical('@ip "0:0:0:0:0:0:0:1"'));
        self::assertSame('@ip "::"', self::canonical('@ip "0:0:0:0:0:0:0:0"'));
        self::assertSame('@ip "1:0:2:3:4:5:6:7"', self::canonical('@ip "1:0:2:3:4:5:6:7"'));
        // The longest run of zero groups is shortened, the first on a tie.
        self::assertSame('@ip "1:0:0:2::3"', self::canonical('@ip "1:0:0:2:0:0:0:3"'));
        self::assertSame('@ip "1::2:0:0:3"', self::canonical('@ip "1:0:0:0:2:0:0:3"'));
        self::assertSame('@ip "1::2:0:0:3:4"', self::canonical('@ip "1:0:0:2:0:0:3:4"'));
    }

    // Erlang's :inet.ntoa writes a link-local address with a non-zero
    // second group as BSD's scope form, fe80::1%01, which is no RFC 5952
    // and which no reader takes back (dextrin e8ece1f).
    public function testEveryAddressIsWrittenInRfc5952sFormAndReadsBack(): void
    {
        foreach (['fe80:1::1', 'ff02:1::2', 'fe80::1', '::ffff:192.0.2.1', '1:2:3:4:5:6:7:0'] as $text) {
            self::assertSame("@ip \"{$text}\"", self::canonical("@ip \"{$text}\""), $text);
        }
    }

    public function testASignIsNoPartOfAnAddress(): void
    {
        foreach (['+1.2.3.4', '1.+2.3.4', '+::1'] as $bad) {
            self::assertSame('syntax', self::category("@ip \"{$bad}\""), $bad);
        }
    }

    public function testAnIpv4PartWithALeadingZeroOrAGroupOfFiveDigitsIsRefused(): void
    {
        foreach (['010.0.0.1', '00001::', '::1.2.3.04', '1:2:3:4:5:6:7:8:9'] as $bad) {
            self::assertSame('syntax', self::category("@ip \"{$bad}\""), $bad);
        }
    }

    public function testIsRawBytesInDxnbRfc9164AnAddressWholeAPrefixTrimmed(): void
    {
        // 0xD8 0x34: tag 52; 0x44: a 4-byte byte string.
        self::assertSame("\xd8\x34\x44\xc0\x00\x02\x01", self::binary('@ip "192.0.2.1"'));
        // [8, h'0a']: the length, then the bytes without trailing zeros.
        self::assertSame("\xd8\x34\x82\x08\x41\x0a", self::binary('@ip "10.0.0.0/8"'));
        // 0xD8 0x36: tag 54.
        $v6 = self::binary('@ip "2001:db8::1"');
        self::assertSame("\xd8\x36\x50", substr($v6, 0, 3));
        self::assertSame(19, strlen($v6));

        foreach (['@ip "192.0.2.1"', '@ip "10.0.0.0/8"', '@ip "2001:db8::/32"', '@ip "::/0"'] as $text) {
            $value = Dextrin::decode($text);
            self::assertEquals($value, Dextrin::decodeBinary(Dextrin::encodeBinary($value)), $text);
        }
    }

    public function testAPrefixWithHostBitsSetInDxnbIsRefused(): void
    {
        try {
            // [8, h'0a01']: 10.1.0.0/8, its host bits not zero.
            Dextrin::decodeBinary("DX\x01\xd8\x34\x82\x08\x42\x0a\x01");
            self::fail('a prefix with host bits set decoded');
        } catch (DXNError $err) {
            self::assertSame('binary', $err->category);
        }
    }

    public function testAPrefixWithHostBitsSetAZoneOrAShorthandAddressIsRefused(): void
    {
        foreach (['10.0.0.1/8', '10.0.0.0/33', 'fe80::1%eth0', '10.1', 'nope'] as $bad) {
            self::assertSame('syntax', self::category("@ip \"{$bad}\""), $bad);
        }
    }

    // ---- in schemas -------------------------------------------------------------

    /** A registry where `T` has one field `v` of type `$type`. */
    private static function registry(string $type): Registry
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

    public function testObjectIdAndIpAndAnIpsVersionAndPrefix(): void
    {
        self::assertTrue(self::takes(self::registry(':objectid'), '@objectid "507f1f77bcf86cd799439011"'));
        self::assertFalse(self::takes(self::registry(':objectid'), '"507f1f77bcf86cd799439011"'));
        self::assertTrue(self::takes(self::registry(':ip'), '@ip "10.0.0.0/8"'));

        $v6 = self::registry('{:refine :ip %{version: 6, prefix: false}}');
        self::assertTrue(self::takes($v6, '@ip "2001:db8::1"'));
        self::assertFalse(self::takes($v6, '@ip "192.0.2.1"'));
        self::assertFalse(self::takes($v6, '@ip "2001:db8::/32"'));
    }
}
