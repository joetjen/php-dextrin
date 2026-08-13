<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNRegex;
use PHPUnit\Framework\TestCase;

final class DXNRegexTest extends TestCase
{
    public function testDefaultsUnsetFlagsToFalse(): void
    {
        $re = new DXNRegex('foo', ['i' => true]);
        self::assertTrue($re->flags['i']);
        self::assertFalse($re->flags['m']);
        self::assertFalse($re->flags['f']);
    }

    public function testRejectsUnknownFlagNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNRegex('foo', ['bogus' => true]);
    }

    public function testToPcrePatternMapsIMSUXDirectlyAndRToUppercaseU(): void
    {
        $re = new DXNRegex('a.*b', ['i' => true, 'm' => true, 's' => true, 'u' => true, 'x' => true, 'r' => true]);
        self::assertSame('/a.*b/imsuxU', $re->toPcrePattern());
    }

    public function testToPcrePatternThrowsWhenFirstlineIsSet(): void
    {
        // "f" (firstline) has no PHP preg modifier equivalent -- the
        // one DXN flag this port's PCRE fidelity can't reach, unlike
        // x/r which do have real modifiers (x, and r->U).
        $re = new DXNRegex('foo', ['f' => true]);
        $this->expectException(\LogicException::class);
        $re->toPcrePattern();
    }

    public function testToPcrePatternEscapesTheDelimiter(): void
    {
        $re = new DXNRegex('a/b');
        self::assertSame('/a\\/b/', $re->toPcrePattern());
    }

    public function testActuallyCompilesAsPcre(): void
    {
        $re = new DXNRegex('^[a-z]+$', ['i' => true]);
        self::assertSame(1, preg_match($re->toPcrePattern(), 'ABC'));
    }

    public function testFromPcrePatternRoundTrips(): void
    {
        $re = DXNRegex::fromPcrePattern('/abc/imU');
        self::assertSame('abc', $re->pattern);
        self::assertTrue($re->flags['i']);
        self::assertTrue($re->flags['m']);
        self::assertTrue($re->flags['r']);
        self::assertFalse($re->flags['s']);
    }

    public function testEquals(): void
    {
        self::assertTrue((new DXNRegex('a', ['i' => true]))->equals(new DXNRegex('a', ['i' => true])));
        self::assertFalse((new DXNRegex('a'))->equals(new DXNRegex('b')));
    }

    public function testDxnType(): void
    {
        self::assertSame('regex', (new DXNRegex('a'))->dxnType());
    }
}
