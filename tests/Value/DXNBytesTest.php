<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNBytes;
use PHPUnit\Framework\TestCase;

final class DXNBytesTest extends TestCase
{
    public function testWrapsRawBinaryData(): void
    {
        $bytes = new DXNBytes("\x00\x01\xFF");
        self::assertSame("\x00\x01\xFF", $bytes->data);
    }

    public function testEqualsStructurallyByData(): void
    {
        self::assertTrue((new DXNBytes('abc'))->equals(new DXNBytes('abc')));
        self::assertFalse((new DXNBytes('abc'))->equals(new DXNBytes('abd')));
    }

    public function testDxnType(): void
    {
        self::assertSame('bytes', (new DXNBytes(''))->dxnType());
    }
}
