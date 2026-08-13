<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNDecimal;
use PHPUnit\Framework\TestCase;

final class DXNDecimalTest extends TestCase
{
    public function testToStringRendersCanonicalDecimal(): void
    {
        self::assertSame('19.99', (new DXNDecimal(1, '1999', -2))->toString());
        self::assertSame('-19.99', (new DXNDecimal(-1, '1999', -2))->toString());
        self::assertSame('100', (new DXNDecimal(1, '1', 2))->toString());
        self::assertSame('0.001', (new DXNDecimal(1, '1', -3))->toString());
        self::assertSame('0', (new DXNDecimal(1, '0', 0))->toString());
    }

    public function testFromFloatRoundTripsWithoutBinaryNoise(): void
    {
        foreach ([19.99, 0.1, 100.0, -0.0001, 3.14159] as $n) {
            self::assertSame($n, DXNDecimal::fromFloat($n)->toFloat(), "fromFloat({$n})");
        }
    }

    public function testFromFloatHandlesScientificNotationMagnitudes(): void
    {
        $d = DXNDecimal::fromFloat(1.0e20);
        self::assertSame('100000000000000000000', $d->toString());
    }

    public function testFromFloatRejectsNanAndInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DXNDecimal::fromFloat(NAN);
    }

    public function testUnscaledValueStripsLeadingZeros(): void
    {
        $d = new DXNDecimal(1, '030', -1);
        self::assertSame('30', $d->unscaledValue);
        self::assertSame('3.0', $d->toString());
    }

    public function testEqualsIsStructuralNotNumeric(): void
    {
        // 1.0 and 1.00 are structurally distinct representations, same
        // "never silently normalized" discipline as DXNRational.
        $a = new DXNDecimal(1, '10', -1);
        $b = new DXNDecimal(1, '100', -2);
        self::assertFalse($a->equals($b));
        self::assertSame($a->toFloat(), $b->toFloat());
    }

    public function testCompareToOrdersByNumericValue(): void
    {
        $a = new DXNDecimal(1, '10', -1); // 1.0
        $b = new DXNDecimal(1, '100', -2); // 1.00, numerically equal
        self::assertSame(0, $a->compareTo($b));

        $small = new DXNDecimal(1, '5', -1); // 0.5
        $large = new DXNDecimal(1, '15', -1); // 1.5
        self::assertLessThan(0, $small->compareTo($large));
    }

    public function testRejectsInvalidSign(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNDecimal(0, '1', 0);
    }

    public function testDxnType(): void
    {
        self::assertSame('decimal', (new DXNDecimal(1, '1', 0))->dxnType());
    }
}
