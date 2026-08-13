<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNCustomTag;
use PHPUnit\Framework\TestCase;

final class DXNCustomTagTest extends TestCase
{
    public function testWrapsNameAndValue(): void
    {
        $tag = new DXNCustomTag('my-app/money', 500);
        self::assertSame('my-app/money', $tag->name);
        self::assertSame(500, $tag->value);
    }

    public function testEqualsRecursesIntoValue(): void
    {
        $a = new DXNCustomTag('x', new \JOetjen\Dextrin\Value\DXNSymbol('a'));
        $b = new DXNCustomTag('x', new \JOetjen\Dextrin\Value\DXNSymbol('a'));
        $c = new DXNCustomTag('x', new \JOetjen\Dextrin\Value\DXNSymbol('b'));
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function testDifferentNamesAreNeverEqual(): void
    {
        self::assertFalse((new DXNCustomTag('x', 1))->equals(new DXNCustomTag('y', 1)));
    }

    public function testDxnType(): void
    {
        self::assertSame('custom-tag', (new DXNCustomTag('x', 1))->dxnType());
    }
}
