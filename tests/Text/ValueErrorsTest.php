<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
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

    public function testAnImpossibleDateIsASyntaxErrorAtItsTag(): void
    {
        self::assertSame(['category' => 'syntax', 'offset' => 1], self::refusal('[@date "2020-13-45"]'));
    }

    public function testAnImpossibleTimeIsASyntaxErrorAtItsTag(): void
    {
        self::assertSame(['category' => 'syntax', 'offset' => 5], self::refusal('%{a: @time "25:00:00"}'));
    }

    // As the reference places it: line 2, column 3.
    public function testOnALaterLineToo(): void
    {
        self::assertSame(['category' => 'syntax', 'offset' => 4], self::refusal("[\n  @date \"2020-13-45\"]"));
    }

    public function testARegexThatIsNotOneIsASyntaxErrorAtItsTag(): void
    {
        self::assertSame(['category' => 'syntax', 'offset' => 0], self::refusal('@regex "("'));
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

    // As the reference places it: at the key, line 2, column 2.
    public function testAMapKeyThatIsNotAllowedIsPlacedAtTheKey(): void
    {
        self::assertSame(['category' => 'key/not-allowed', 'offset' => 8], self::refusal("%{a: 1\n 1.5 => 2}"));
    }

    public function testInAStructsExtraFieldsToo(): void
    {
        self::assertSame(['category' => 'key/not-allowed', 'offset' => 9], self::refusal('%P{a: 1, 1.5 => 2}'));
    }

    // Written as DXN, as the reference writes it.
    public function testARefusedKeyIsNamedAsWritten(): void
    {
        try {
            Dextrin::decode('%{[1] => 2}');
        } catch (DXNError $err) {
            self::assertStringStartsWith('[1] cannot be a map key', $err->getMessage());
            return;
        }
        self::fail('expected a refusal');
    }

    // As the reference places it: at the innermost struct, line 2, column 3.
    public function testASchemaViolationIsPlacedAtItsInnermostStruct(): void
    {
        $registry = Schema::compile(Dextrin::decode('%{U: %schema{a: :string} V: %schema{u: U}}'), new Registry());
        try {
            Dextrin::decode("%V{u:\n  %U{a: 2}}", $registry);
        } catch (DXNError $err) {
            self::assertSame(['schema/type-mismatch', 8], [$err->category, $err->offset]);
            return;
        }
        self::fail('expected a refusal');
    }

    // An error that has a category of its own keeps it.
    public function testAMapKeyThatIsNotAllowedStaysKeyNotAllowed(): void
    {
        self::assertSame('key/not-allowed', self::refusal('%{1.5 => 1}')['category']);
    }
}
