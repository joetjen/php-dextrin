<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNChar;
use PHPUnit\Framework\TestCase;

final class DXNCharTest extends TestCase
{
    public function testRoundTripsAscii(): void
    {
        $c = DXNChar::fromString('a');
        self::assertSame(97, $c->codepoint);
        self::assertSame('a', $c->toString());
    }

    public function testRoundTripsMultibyteCodepoint(): void
    {
        $c = DXNChar::fromString('é');
        self::assertSame(233, $c->codepoint);
        self::assertSame('é', $c->toString());
    }

    public function testRejectsMultiCodepointString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DXNChar::fromString('ab');
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DXNChar::fromString('');
    }

    public function testRejectsOutOfRangeCodepoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNChar(0x110000);
    }

    public function testEqualsAndCompareTo(): void
    {
        self::assertTrue((new DXNChar(97))->equals(new DXNChar(97)));
        self::assertLessThan(0, (new DXNChar(97))->compareTo(new DXNChar(98)));
    }

    public function testDxnType(): void
    {
        self::assertSame('char', (new DXNChar(97))->dxnType());
    }
}
