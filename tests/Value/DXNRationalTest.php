<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNRational;
use PHPUnit\Framework\TestCase;

final class DXNRationalTest extends TestCase
{
    public function testNeverAutoReduces(): void
    {
        $fourHalves = new DXNRational('4', '2');
        $twoOnes = new DXNRational('2', '1');

        self::assertSame('4', $fourHalves->numerator);
        self::assertSame('2', $fourHalves->denominator);
        self::assertFalse($fourHalves->equals($twoOnes), '4/2 and 2/1 must stay structurally distinct');
        self::assertSame(0, $fourHalves->compareTo($twoOnes), 'but still numerically equal via compareTo');
    }

    public function testFromFloatIsExact(): void
    {
        foreach ([0.5, 1.0, 3.14, -0.25, 100.0, 1 / 3, -7.0] as $n) {
            self::assertSame($n, DXNRational::fromFloat($n)->toFloat(), "fromFloat({$n})");
        }
    }

    public function testFromFloatZero(): void
    {
        $r = DXNRational::fromFloat(0.0);
        self::assertSame('0', $r->numerator);
        self::assertSame('1', $r->denominator);
    }

    public function testFromFloatRejectsNanAndInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DXNRational::fromFloat(INF);
    }

    public function testRejectsZeroDenominator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNRational('1', '0');
    }

    public function testRejectsZeroDenominatorWithLeadingZeros(): void
    {
        // "00" passes the unsigned-digits-only regex but normalizes
        // to zero just as much as "0" does -- must be checked after
        // normalization, not before.
        $this->expectException(\InvalidArgumentException::class);
        new DXNRational('1', '00');
    }

    public function testCompareToOrdersByValueAcrossDifferentDenominators(): void
    {
        $oneHalf = new DXNRational('1', '2');
        $twoThirds = new DXNRational('2', '3');
        self::assertLessThan(0, $oneHalf->compareTo($twoThirds));
        self::assertGreaterThan(0, $twoThirds->compareTo($oneHalf));
    }

    public function testDxnType(): void
    {
        self::assertSame('rational', (new DXNRational('1', '2'))->dxnType());
    }
}
