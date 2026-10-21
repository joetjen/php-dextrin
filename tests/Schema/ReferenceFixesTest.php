<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\Docs;
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
 *
 * The node port's and this port's own cross-checks found more in the
 * reference (dextrin 7c45598, 3b0ccc6): `bits` is a built-in tag, an
 * enum's literal is the value itself, the docs survive a value annotation
 * that is no map, a generic checked by its own name is refused. This port
 * did all four already; the reference's tests are ported so it stays so.
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

    // The node port's cross-check (dextrin 7c45598).

    public function testBitsIsABuiltInTagNoCustomOne(): void
    {
        try {
            self::compiled('{:tagged bits :any}');
            self::fail('bits should be refused as a custom tag');
        } catch (SchemaCompileError $err) {
            self::assertStringContainsString('built-in', $err->getMessage());
        }
    }

    public function testAnEnumsLiteralIsTheValueItselfOneIsNotOnePointZero(): void
    {
        self::assertFalse(self::takes('{:enum 1}', 1.0));
        self::assertFalse(self::takes('{:const 1.0}', 1));
        self::assertTrue(self::takes('{:enum 1 2}', 2));
        self::assertFalse(self::takes('{:enum %{a: 1}}', Dextrin::decode('%{a: 1.0}')));
    }

    public function testTheDocsShowAValueWhoseAnnotationsAreNoMapAndDoNotCrash(): void
    {
        $root = sys_get_temp_dir() . '/dextrin-fix-' . uniqid('', true);
        mkdir($root, 0777, true);
        file_put_contents("{$root}/c.dxns", '%{C: {:doc {:enum :a} %{values: %{:a => 5}}}}');
        self::assertNotEmpty(iterator_to_array(Docs::site($root, 'T'), false));
    }

    // This port's own cross-check (dextrin 3b0ccc6).

    public function testAGenericCheckedByItsOwnNameRefusesTheValueAndRaisesNothingElse(): void
    {
        $registry = Schema::compile(Dextrin::decode(<<<'DXN'
            %{
              Page: {:generic [t] %shape{items: {:list-of t}}}
              Doc: {:doc {:generic [t] {:list-of t}} %{description: "x"}}
            }
            DXN), new Registry());
        foreach (['Page', 'Doc'] as $name) {
            try {
                Schema::check(Dextrin::decode('%{items: []}'), $registry, $name);
                self::fail("{$name} should refuse the value");
            } catch (SchemaValidationError $err) {
                self::assertSame('schema/type-mismatch', $err->category(), $name);
            }
        }
    }

    /**
     * A map's keys are checked as the values they are, not as the PHP array
     * keys that hold them: a string key is stored with a leading space, to
     * tell `"a" =>` from `a:` (found by bench/schema-validation.php).
     */
    public function testAMapOfKeyIsCheckedAsItsDxnValue(): void
    {
        $this->assertTrue(self::takes('{:map-of {:refine :string %{pattern: @regex "^n[0-9]{1,4}$"}} :any}', Dextrin::decode('%{"n1" => 1}')));
        $this->assertFalse(self::takes('{:map-of {:refine :string %{pattern: @regex "^n[0-9]{1,4}$"}} :any}', Dextrin::decode('%{"N1" => 1}')));
        $this->assertTrue(self::takes('{:map-of :keyword :any}', Dextrin::decode('%{b: 1}')));
        $this->assertFalse(self::takes('{:map-of :string :any}', Dextrin::decode('%{b: 1}')));
        $this->assertTrue(self::takes('{:map-of :string :any}', Dextrin::decode('%{"b" => 1}')));
        $this->assertTrue(self::takes('{:map-of :integer :any}', Dextrin::decode('%{1 => 1}')));
    }

    /**
     * A map whose keys PHP cannot hold as array keys -- symbols, UUIDs,
     * dates -- decodes to a DXNMap, and a {:map-of} checks it too (found by
     * bench/schema-validation.php).
     */
    public function testAMapOfChecksAMapPhpHoldsAsADxnMap(): void
    {
        $this->assertTrue(self::takes('{:map-of :symbol :integer}', Dextrin::decode('%{total => 1, rate => 2}')));
        $this->assertFalse(self::takes('{:map-of :symbol :integer}', Dextrin::decode('%{total => "x"}')));
        $this->assertFalse(self::takes('{:map-of :string :integer}', Dextrin::decode('%{total => 1}')));
        $this->assertTrue(self::takes('{:map-of :date :integer}', Dextrin::decode('%{@date "2026-10-21" => 1}')));
    }
}
