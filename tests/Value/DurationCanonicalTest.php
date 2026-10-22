<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Value;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\SchemaValidationError;
use JOetjen\Dextrin\Value\DXNDuration;
use PHPUnit\Framework\TestCase;

/**
 * A duration's zero fields are left out when it is written, in text and
 * in `.dxnb`, and one that is zero throughout is `PT0S` (DXN.md §1.5), as
 * dextrin 80c4e88. Reading keeps what was written: only the writer drops
 * a zero field.
 */
final class DurationCanonicalTest extends TestCase
{
    private static function rewritten(string $text): string
    {
        return Dextrin::encode(Dextrin::decode($text));
    }

    public function testAZeroFieldIsLeftOut(): void
    {
        self::assertSame('@duration "P1D"', self::rewritten('@duration "P1DT0H"'));
        self::assertSame('@duration "P2MT3H"', self::rewritten('@duration "P0Y2M0DT3H0M0S"'));
        self::assertSame('@duration "PT30M"', self::rewritten('@duration "PT0H30M"'));
    }

    public function testADurationThatIsZeroThroughoutIsPT0S(): void
    {
        foreach (['P0D', 'P0Y0M', 'PT0H', 'PT0M0S', 'PT0.000S'] as $text) {
            self::assertSame('@duration "PT0S"', self::rewritten("@duration \"{$text}\""), $text);
        }
    }

    public function testANegativeFieldIsKept(): void
    {
        self::assertSame('@duration "P-1D"', self::rewritten('@duration "P-1DT0H"'));
        self::assertSame('@duration "PT-0.5S"', self::rewritten('@duration "PT-0.5S"'));
    }

    public function testReadingKeepsAZeroField(): void
    {
        $d = Dextrin::decode('@duration "P0D"');
        self::assertInstanceOf(DXNDuration::class, $d);
        self::assertSame(0, $d->days);
        self::assertNull($d->hours);
    }

    public function testDxnbHoldsOnlyTheFieldsThatAreNotZero(): void
    {
        $withZero = Dextrin::encodeBinary(Dextrin::decode('@duration "P1DT0H"'));
        self::assertSame(Dextrin::encodeBinary(Dextrin::decode('@duration "P1D"')), $withZero);
        $back = Dextrin::decodeBinary($withZero);
        self::assertInstanceOf(DXNDuration::class, $back);
        self::assertSame(1, $back->days);
        self::assertNull($back->hours);
    }

    public function testAnAllZeroDurationInDxnbHasNoFieldAndReadsBackAsPT0S(): void
    {
        $back = Dextrin::decodeBinary(Dextrin::encodeBinary(Dextrin::decode('@duration "P0D"')));
        self::assertEquals(new DXNDuration(), $back);
        self::assertSame('@duration "PT0S"', Dextrin::encode($back));
    }

    public function testP0DAndPT0SAreOneValueToAUniquenessCheck(): void
    {
        $registry = Schema::compile(Dextrin::decode('%{T: {:refine {:list-of :duration} %{unique: true}}}'), new Registry());
        $this->expectException(SchemaValidationError::class);
        Schema::check(Dextrin::decode('[@duration "P0D" @duration "PT0S"]'), $registry, 'T');
    }
}
