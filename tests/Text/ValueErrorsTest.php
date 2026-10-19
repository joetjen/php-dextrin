<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use PHPUnit\Framework\TestCase;

/**
 * A value that is written well but is not a value -- an impossible date,
 * a uuid that is not one, an unknown escape -- is text that does not
 * parse: `syntax` (DXN.md §5), placed at the value it is about, as the
 * reference implementation answers. These came back with no category
 * and no offset.
 */
final class ValueErrorsTest extends TestCase
{
    /** @return array{category: ?string, offset: ?int} */
    private static function refusal(string $text): array
    {
        try {
            Dextrin::decode($text);
        } catch (DXNError $err) {
            return ['category' => $err->category, 'offset' => $err->offset];
        }
        self::fail("expected {$text} to be refused");
    }

    public function testAUuidThatIsNotOneIsASyntaxErrorAtItsTag(): void
    {
        self::assertSame(['category' => 'syntax', 'offset' => 3], self::refusal('[1 @uuid "nope"]'));
    }

    public function testAnImpossibleDateIsASyntaxErrorAtItsSigil(): void
    {
        self::assertSame(['category' => 'syntax', 'offset' => 1], self::refusal('[~D[2020-13-45]]'));
    }

    public function testAnImpossibleTimeIsASyntaxErrorAtItsSigil(): void
    {
        self::assertSame(['category' => 'syntax', 'offset' => 5], self::refusal('%{a: ~T[25:00:00]}'));
    }

    public function testADatetimeThatIsNotOneIsASyntaxErrorAtItsTag(): void
    {
        self::assertSame(['category' => 'syntax', 'offset' => 0], self::refusal('@datetime "x"'));
    }

    public function testAnUnknownEscapeIsASyntaxErrorAtItsString(): void
    {
        self::assertSame(['category' => 'syntax', 'offset' => 3], self::refusal('[1 "\\q"]'));
    }

    public function testAZeroDenominatorIsASyntaxErrorAtItsRational(): void
    {
        self::assertSame(['category' => 'syntax', 'offset' => 5], self::refusal('%{a: 1/0}'));
    }

    // An error that has a category of its own keeps it.
    public function testAMapKeyThatIsNotAllowedStaysKeyNotAllowed(): void
    {
        self::assertSame('key/not-allowed', self::refusal('%{1.5 => 1}')['category']);
    }
}
