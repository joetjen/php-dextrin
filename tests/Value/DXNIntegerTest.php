<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Value\DXNInteger;
use PHPUnit\Framework\TestCase;

final class DXNIntegerTest extends TestCase
{
    public function testRepresentReturnsNativeIntWhenItFits(): void
    {
        self::assertSame(42, DXNInteger::represent('42'));
        self::assertSame(PHP_INT_MAX, DXNInteger::represent((string) PHP_INT_MAX));
        self::assertSame(PHP_INT_MIN, DXNInteger::represent((string) PHP_INT_MIN));
    }

    public function testRepresentReturnsDXNIntegerOneOverTheBoundary(): void
    {
        $overMax = DXNInteger::represent(bcadd((string) PHP_INT_MAX, '1', 0));
        self::assertInstanceOf(DXNInteger::class, $overMax);
        self::assertSame(bcadd((string) PHP_INT_MAX, '1', 0), $overMax->toString());

        $underMin = DXNInteger::represent(bcsub((string) PHP_INT_MIN, '1', 0));
        self::assertInstanceOf(DXNInteger::class, $underMin);
    }

    public function testNormalizesLeadingZerosAndSign(): void
    {
        self::assertSame('42', (new DXNInteger('042'))->toString());
        self::assertSame('0', (new DXNInteger('-0'))->toString());
        self::assertSame('-42', (new DXNInteger('-042'))->toString());
    }

    public function testRejectsNonNumericStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DXNInteger('12.5');
    }

    public function testArithmetic(): void
    {
        $huge = DXNInteger::fromString('99999999999999999999999999999');
        self::assertSame('100000000000000000000000000000', $huge->plus(1)->toString());
        self::assertSame('99999999999999999999999999998', $huge->minus(1)->toString());
        self::assertSame('-99999999999999999999999999999', $huge->negate()->toString());
    }

    public function testFitsInIntAndToInt(): void
    {
        self::assertTrue((new DXNInteger('42'))->fitsInInt());
        self::assertFalse(DXNInteger::fromString('99999999999999999999')->fitsInInt());

        $this->expectException(\OverflowException::class);
        DXNInteger::fromString('99999999999999999999')->toInt();
    }

    public function testCompareToAndEquals(): void
    {
        $a = DXNInteger::fromString('99999999999999999999');
        $b = DXNInteger::fromString('99999999999999999999');
        $c = DXNInteger::fromString('99999999999999999998');

        self::assertTrue($a->equals($b));
        self::assertSame(0, $a->compareTo($b));
        self::assertGreaterThan(0, $a->compareTo($c));
        self::assertLessThan(0, $c->compareTo($a));
        self::assertFalse($a->equals(42));
    }

    public function testDxnType(): void
    {
        self::assertSame('integer', (new DXNInteger('1'))->dxnType());
    }
}
