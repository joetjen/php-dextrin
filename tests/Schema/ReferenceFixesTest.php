<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\SchemaCompileError;
use JOetjen\Dextrin\Schema\SchemaValidationError;
use PHPUnit\Framework\TestCase;

/**
 * What this port's cross-check found in the reference (2026-10-20; as
 * dextrin a56339b): NaN and the infinities as bounds, `$` before a final
 * newline, a crash in `host-port`, hostname lengths in graphemes, an
 * unknown type in `inspect`'s words. The reference's sixth fix, a trusted
 * decode reading six keywords as the values whose atoms they share, has
 * nothing to port: this port has no trusted decode, a keyword is always
 * a `DXNKeyword` ({@see \JOetjen\Dextrin\Registry}'s doc).
 */
final class ReferenceFixesTest extends TestCase
{
    private static function compiled(string $type): Registry
    {
        return Schema::compile(Dextrin::decode("%{T: {$type}}"), new Registry());
    }

    /** Whether `$value` is a `$type`; only a type mismatch answers false. */
    private static function takes(string $type, mixed $value): bool
    {
        $registry = self::compiled($type);
        try {
            return Schema::check($value, $registry, 'T');
        } catch (SchemaValidationError $err) {
            self::assertSame('schema/type-mismatch', $err->category());
            return false;
        }
    }

    /** Whether `$type` is refused when it compiles. */
    private static function refused(string $type): bool
    {
        try {
            self::compiled($type);
            return false;
        } catch (SchemaCompileError) {
            return true;
        }
    }

    public function testNanAndTheInfinitiesAreNumbersInASchemaBoundsLikeAnyOther(): void
    {
        self::assertFalse(self::refused('{:refine :float %{max: Infinity}}'));
        self::assertTrue(self::takes('{:refine :float %{min: -Infinity}}', 1.0));
        self::assertFalse(self::takes('{:refine :float %{min: NaN}}', 1.0));
        self::assertTrue(self::takes('{:enum NaN}', NAN));
    }

    public function testAGrammarsEndIsTheStringsEndAFinalNewlineIsRefused(): void
    {
        // A json-pointer's tokens may hold a newline, so it is no example.
        foreach ([
            'hostname' => 'example.com',
            'semver' => '1.2.3',
            'mac' => '00:1a:2b:3c:4d:5e',
            'uri' => 'https://example.com',
            'email' => 'a@example.com',
            'uri-template' => '/{id}',
            'host-port' => 'a:80',
        ] as $format => $good) {
            self::assertTrue(self::takes("{:refine :string %{format: :{$format}}}", $good), $format);
            self::assertFalse(self::takes("{:refine :string %{format: :{$format}}}", "{$good}\n"), $format);
        }
    }

    public function testDatesAndTimesReadNoFinalNewlineEither(): void
    {
        foreach ([
            'date' => '2026-10-20',
            'time' => '12:00:00',
            'datetime' => '2026-10-20T12:00:00Z',
            'local-datetime' => '2026-10-20T12:00:00',
            'duration' => 'P1D',
        ] as $format => $text) {
            self::assertFalse(self::takes("{:refine :string %{format: :{$format}}}", "{$text}\n"), $format);
            $refused = false;
            try {
                Dextrin::decode("@{$format} \"{$text}\\n\"");
            } catch (\Throwable) {
                $refused = true;
            }
            self::assertTrue($refused, "@{$format} should refuse a final newline");
        }
    }

    public function testAnOffsetAndAMediaTypeInASchemaEndWhereTheStringDoes(): void
    {
        self::assertTrue(self::refused('{:refine :datetime %{offsets: ["Z\n"]}}'));
        self::assertTrue(self::refused('{:refine :string %{media-type: "a/b\n"}}'));
    }

    public function testAHostnames253AreCodepoints(): void
    {
        $label = str_repeat("e\u{301}", 31);
        $name = implode('.', array_fill(0, 4, $label)) . '.ab';
        self::assertLessThanOrEqual(253, (int) preg_match_all('/\X/u', $name));
        self::assertGreaterThan(253, mb_strlen($name, 'UTF-8'));
        self::assertFalse(self::takes('{:refine :string %{format: :idn-hostname}}', $name));
    }

    public function testAnUnknownTypeIsWrittenInDxnInItsMessage(): void
    {
        try {
            self::compiled('{:list-of :nope}');
            self::fail('an unknown type should be refused');
        } catch (SchemaCompileError $err) {
            self::assertStringContainsString(':nope', $err->getMessage());
            self::assertStringNotContainsString('DXNKeyword', $err->getMessage());
        }
    }
}
