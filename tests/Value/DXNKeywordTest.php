<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNSymbol;
use PHPUnit\Framework\TestCase;

final class DXNKeywordTest extends TestCase
{
    public function testWrapsAName(): void
    {
        self::assertSame('foo', (new DXNKeyword('foo'))->name);
    }

    public function testEqualsStructurallyByNameAndIsDistinctFromSymbol(): void
    {
        self::assertTrue((new DXNKeyword('foo'))->equals(new DXNKeyword('foo')));
        self::assertFalse((new DXNKeyword('foo'))->equals(new DXNKeyword('bar')));
        // Same name, different DXN type -- keyword and symbol never
        // compare equal to each other, even structurally identical.
        self::assertFalse((new DXNKeyword('foo'))->equals(new DXNSymbol('foo')));
    }

    public function testCompareToOrdersByName(): void
    {
        self::assertLessThan(0, (new DXNKeyword('a'))->compareTo(new DXNKeyword('b')));
    }

    public function testDxnType(): void
    {
        self::assertSame('keyword', (new DXNKeyword('foo'))->dxnType());
    }
}
