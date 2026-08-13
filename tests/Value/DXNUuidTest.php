<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNUuid;
use PHPUnit\Framework\TestCase;

final class DXNUuidTest extends TestCase
{
    public function testRequiresExactly16Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNUuid('too short');
    }

    public function testRoundTripsThroughToStringFromString(): void
    {
        $uuid = DXNUuid::fromString('550e8400-e29b-41d4-a716-446655440000');
        self::assertSame(16, strlen($uuid->bytes));
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $uuid->toString());
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', (string) $uuid);
    }

    public function testIsCaseInsensitiveOnParseButLowercasesOnFormat(): void
    {
        $uuid = DXNUuid::fromString('550E8400-E29B-41D4-A716-446655440000');
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $uuid->toString());
    }

    public function testRejectsAMalformedUuidString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DXNUuid::fromString('not-a-uuid');
    }

    public function testEqualsStructurallyByBytes(): void
    {
        $a = DXNUuid::fromString('550e8400-e29b-41d4-a716-446655440000');
        $b = DXNUuid::fromString('550e8400-e29b-41d4-a716-446655440000');
        self::assertTrue($a->equals($b));
    }

    public function testDxnType(): void
    {
        self::assertSame('uuid', DXNUuid::fromString('550e8400-e29b-41d4-a716-446655440000')->dxnType());
    }
}
