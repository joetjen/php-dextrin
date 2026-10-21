<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\Field;
use JOetjen\Dextrin\Schema\SchemaValidationError;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use PHPUnit\Framework\TestCase;

/**
 * `{:doc T %{...}}`, the superset's group 8 (design/schema-superset.md,
 * Jan, 2026-10-20; as dextrin 0b4225d): a title, a description, examples,
 * read-only, write-only, deprecated and free `meta` on any type, a whole
 * schema included, and docs for single enum values. Annotations say
 * things about a type; they never change what it takes.
 */
final class AnnotationsTest extends TestCase
{
    private const SCHEMAS = <<<'DXN'
        %{
          Order: {:doc %schema(2){
            id: :integer
            email: {:doc :string %{description: "Where receipts go", meta: %{"acme.pii" => true}}}
            legacy?: {:doc :string %{deprecated: "use email"}}
          } %{title: "An order", description: "One checkout", deprecated: false}}

          Color: {:doc {:enum :red :green :old-blue} %{
            description: "A color"
            values: %{:old-blue => %{deprecated: true, description: "Use :blue"}}
          }}

          Port: {:doc {:refine :integer %{min: 1, max: 65535}} %{examples: [80 443], read-only: true}}

          Page: {:doc {:generic [t] %shape{items: {:list-of t}}} %{description: "A page of anything"}}
        }
        DXN;

    private static function registry(string $text = self::SCHEMAS): Registry
    {
        return Schema::compile(Dextrin::decode($text), new Registry());
    }

    /** The message `$text` is refused with, a `schema/compile` error. */
    private static function compileError(string $text): string
    {
        try {
            self::registry($text);
        } catch (\Throwable $err) {
            $category = match (true) {
                $err instanceof HasCategory => $err->category(),
                $err instanceof DXNError => $err->category,
                default => null,
            };
            self::assertSame('schema/compile', $category, $err->getMessage());
            return $err->getMessage();
        }
        self::fail("{$text} should be refused");
    }

    /** Whether `$value` is a `$name`; only a type mismatch answers false. */
    private static function takes(mixed $value, Registry $registry, string $name): bool
    {
        try {
            return Schema::check($value, $registry, $name);
        } catch (SchemaValidationError $err) {
            self::assertSame('schema/type-mismatch', $err->category());
            return false;
        }
    }

    /** Whether `$text` decodes against `$registry`. */
    private static function decodes(string $text, Registry $registry): bool
    {
        try {
            Dextrin::decode($text, $registry);
            return true;
        } catch (DXNError $err) {
            self::assertSame('schema/type-mismatch', $err->category, $err->getMessage());
            return false;
        }
    }

    public function testAnAnnotatedTypeTakesWhatTheTypeTakesNothingMoreAndNothingLess(): void
    {
        $r = self::registry();
        self::assertTrue(self::takes(80, $r, 'Port'));
        self::assertFalse(self::takes(0, $r, 'Port'));
        self::assertTrue(self::decodes('%Order(2){id: 1, email: "a@b"}', $r));
        self::assertFalse(self::decodes('%Order(2){id: 1, email: 5}', $r));
        self::assertTrue(self::decodes('%Order(2){id: 1, email: "a@b", legacy: "x"}', $r));
    }

    public function testASchemasANamedTypesAndAGenericsAnnotationsAreReadableByName(): void
    {
        $r = self::registry();
        self::assertSame(
            ['deprecated' => false, 'description' => 'One checkout', 'title' => 'An order'],
            self::sorted(Schema::annotations($r, 'Order')),
        );
        $port = Schema::annotations($r, 'Port');
        self::assertEquals(new DXNList([80, 443]), $port['examples'] ?? null);
        self::assertTrue($port['read-only'] ?? null);
        self::assertSame(['description' => 'A page of anything'], Schema::annotations($r, 'Page'));
        self::assertSame([], Schema::annotations(self::registry('%{Plain: :integer}'), 'Plain'));
        self::assertNull(Schema::annotations($r, 'Nothing'));
    }

    public function testAnEnumValuesOwnAnnotationsSitUnderValues(): void
    {
        $values = Schema::annotations(self::registry(), 'Color')['values'] ?? null;
        self::assertIsArray($values);
        self::assertSame(['old-blue'], array_keys($values));
        self::assertEquals(new DXNKeyword('old-blue'), MapBuilder::toDxnMapKey('old-blue'));
        self::assertTrue($values['old-blue']['deprecated'] ?? null);
    }

    public function testAFieldsAnnotationsStayOnItsTypeAndItsMetaIsKeptAsWritten(): void
    {
        $compiled = self::registry()->fetchStructSchema('Order', 2)?->compiled;
        self::assertNotNull($compiled);
        $email = array_values(array_filter($compiled->fields, static fn (Field $f): bool => $f->name === 'email'))[0];
        self::assertSame('doc', $email->type->form);
        self::assertSame('primitive', $email->type->elem?->form);
        self::assertSame('string', $email->type->elem->name);
        self::assertSame(Dextrin::decode('%{"acme.pii" => true}'), $email->type->notes['meta'] ?? null);
    }

    public function testTheKeysAreAClosedSetEachOfItsKind(): void
    {
        foreach ([
            ['%{T: {:doc :integer %{summary: "x"}}}', 'summary'],
            ['%{T: {:doc :integer %{description: 5}}}', 'description'],
            ['%{T: {:doc :integer %{examples: 5}}}', 'examples'],
            ['%{T: {:doc :integer %{read-only: "yes"}}}', 'read-only'],
            ['%{T: {:doc :integer %{deprecated: 5}}}', 'deprecated'],
            ['%{T: {:doc :integer %{meta: [1]}}}', 'meta'],
            ['%{T: {:doc :integer %{values: %{1 => %{}}}}}', 'values'],
            ['%{T: {:doc {:enum :a} %{values: %{:b => %{}}}}}', ':b'],
            ['%{T: {:doc {:enum :a} %{values: %{:a => %{values: %{}}}}}}', 'values'],
            ['%{T: {:doc :integer}}', 'doc'],
        ] as [$text, $key]) {
            self::assertStringContainsString($key, self::compileError($text), $text);
        }
    }

    public function testADocIsNoGuardItChecksTheSameValue(): void
    {
        self::assertStringContainsString('A', self::compileError('%{A: {:doc A %{description: "loops"}}}'));
    }

    /**
     * @param array<string, mixed>|null $notes
     * @return array<string, mixed>|null
     */
    private static function sorted(?array $notes): ?array
    {
        if ($notes !== null) {
            ksort($notes);
        }
        return $notes;
    }
}
