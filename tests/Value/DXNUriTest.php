<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNUri;
use PHPUnit\Framework\TestCase;

final class DXNUriTest extends TestCase
{
    public function testWrapsTheRawStringExactlyUnparsed(): void
    {
        $uri = new DXNUri('https://example.com/path?q=1');
        self::assertSame('https://example.com/path?q=1', $uri->value);
    }

    public function testRoundTripsThroughToStringFromString(): void
    {
        $uri = DXNUri::fromString('urn:isbn:0451450523');
        self::assertSame('urn:isbn:0451450523', $uri->toString());
        self::assertSame('urn:isbn:0451450523', (string) $uri);
    }

    public function testEqualsStructurallyByValue(): void
    {
        self::assertTrue((new DXNUri('a'))->equals(new DXNUri('a')));
        self::assertFalse((new DXNUri('a'))->equals(new DXNUri('b')));
    }

    public function testDxnType(): void
    {
        self::assertSame('uri', (new DXNUri('a'))->dxnType());
    }
}
