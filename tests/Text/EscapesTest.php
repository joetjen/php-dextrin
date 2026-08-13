<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\Text\Escapes;
use PHPUnit\Framework\TestCase;

final class EscapesTest extends TestCase
{
    public function testDecodesNamedEscapes(): void
    {
        self::assertSame("\n", Escapes::decodeString('\\n'));
        self::assertSame("\t", Escapes::decodeString('\\t'));
        self::assertSame("\r", Escapes::decodeString('\\r'));
        self::assertSame('"', Escapes::decodeString('\\"'));
        self::assertSame('\\', Escapes::decodeString('\\\\'));
        self::assertSame("\x00", Escapes::decodeString('\\0'));
        self::assertSame("\x07", Escapes::decodeString('\\a'));
        self::assertSame("\x08", Escapes::decodeString('\\b'));
        self::assertSame("\x0c", Escapes::decodeString('\\f'));
        self::assertSame("\x0b", Escapes::decodeString('\\v'));
    }

    public function testDecodesHexEscape(): void
    {
        self::assertSame('A', Escapes::decodeString('\\x{41}'));
        self::assertSame('😀', Escapes::decodeString('\\x{1F600}'));
    }

    public function testDecodesMixedPlainAndEscapedText(): void
    {
        self::assertSame('hello world', Escapes::decodeString('hello world'));
        self::assertSame("a\nb\tc", Escapes::decodeString('a\\nb\\tc'));
    }

    public function testRejectsUnrecognizedEscape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Escapes::decodeString('\\q');
    }

    public function testRejectsDanglingBackslash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Escapes::decodeString('foo\\');
    }

    public function testRejectsMalformedHexEscape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Escapes::decodeString('\\x{}');
    }

    public function testEncodeOnlyEscapesQuoteBackslashAndControlChars(): void
    {
        self::assertSame('\\"', Escapes::encodeString('"'));
        self::assertSame('\\\\', Escapes::encodeString('\\'));
        self::assertSame('\\n', Escapes::encodeString("\n"));
        self::assertSame('\\t', Escapes::encodeString("\t"));
        self::assertSame('\\r', Escapes::encodeString("\r"));
        self::assertSame('\\x{1}', Escapes::encodeString("\x01"));
        self::assertSame('héllo', Escapes::encodeString('héllo'), 'non-ASCII text stays untouched');
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        foreach (['hello', "line1\nline2", '"quoted"', 'émoji 😀', "\x01\x02"] as $s) {
            self::assertSame($s, Escapes::decodeString(Escapes::encodeString($s)), $s);
        }
    }
}
