<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Value\DXNDateTime;
use PHPUnit\Framework\TestCase;

/**
 * A native `\DateTimeInterface` always means a `datetime`: it keeps its
 * offset, with or without a schema (DXNS.md §7; Jan, 2026-10-19). A
 * host value always means the same DXN type.
 */
final class NativeDateTimeTest extends TestCase
{
    public function testItIsWrittenAsADatetimeKeepingItsOffset(): void
    {
        $at = new \DateTimeImmutable('2024-01-02T03:04:05+02:00');
        self::assertSame(Dextrin::encode(DXNDateTime::fromDateTimeImmutable($at)), Dextrin::encode($at));
    }

    public function testAndReadsBackFromBinaryAsOne(): void
    {
        $at = new \DateTimeImmutable('2024-01-02T03:04:05+02:00');
        self::assertEquals(DXNDateTime::fromDateTimeImmutable($at), Dextrin::decodeBinary(Dextrin::encodeBinary($at)));
    }

    public function testAMutableDateTimeToo(): void
    {
        $at = new \DateTime('2024-01-02T03:04:05-05:00');
        self::assertSame(Dextrin::encode(DXNDateTime::fromDateTimeImmutable(\DateTimeImmutable::createFromMutable($at))), Dextrin::encode($at));
    }
}
