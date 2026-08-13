<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNSymbol;
use PHPUnit\Framework\TestCase;

final class DXNSymbolTest extends TestCase
{
    public function testWrapsAName(): void
    {
        self::assertSame('foo', (new DXNSymbol('foo'))->name);
    }

    public function testEqualsStructurallyByName(): void
    {
        self::assertTrue((new DXNSymbol('foo'))->equals(new DXNSymbol('foo')));
        self::assertFalse((new DXNSymbol('foo'))->equals(new DXNSymbol('bar')));
        self::assertFalse((new DXNSymbol('foo'))->equals('foo'));
    }

    public function testCompareToOrdersByName(): void
    {
        self::assertLessThan(0, (new DXNSymbol('a'))->compareTo(new DXNSymbol('b')));
    }

    public function testDxnType(): void
    {
        self::assertSame('symbol', (new DXNSymbol('foo'))->dxnType());
    }
}
