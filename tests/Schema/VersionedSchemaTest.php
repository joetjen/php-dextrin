<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\Compiled;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

/**
 * Each `.dxns` document describes one version of a schema, written
 * `%schema(N){field: type, ...}` -- the body lists the fields in order,
 * and that order is the positional order. Schemas are kept by name and
 * version (DXN.md §4.8).
 */
final class VersionedSchemaTest extends TestCase
{
    private static function compiled(string $text, ?Registry $registry = null): Registry
    {
        return Schema::compile(Dextrin::decode($text), $registry ?? new Registry());
    }

    private static function version(Registry $registry, string $name, int $version): Compiled
    {
        $found = $registry->fetchStructSchema($name, $version);
        self::assertNotNull($found);
        return $found->compiled;
    }

    /** @param \Closure(): mixed $refused */
    private static function categoryOf(\Closure $refused): ?string
    {
        try {
            $refused();
        } catch (DXNError $err) {
            return $err->category;
        } catch (HasCategory $err) {
            return $err->category();
        }
        self::fail('expected a refusal');
    }

    public function testTheBodyListsTheFieldsDirectlyInOrder(): void
    {
        $registry = self::compiled('%{User: %schema(3){name: :string, email: :string, id?: :uuid}}');
        self::assertSame(['name', 'email', 'id'], self::version($registry, 'User', 3)->fieldNames());
    }

    public function testASchemaWithNoHeaderIsVersionOne(): void
    {
        $registry = self::compiled('%{Point: %schema{x: :integer, y: :integer}}');
        self::assertSame(1, self::version($registry, 'Point', 1)->version);
    }

    public function testAFieldsMetadataIsKeptInAFieldStruct(): void
    {
        $registry = self::compiled('%{P: %schema{x: %field{type: :integer, default: 0, description: "x"}}}');
        [$field] = self::version($registry, 'P', 1)->fields;
        self::assertSame('x', $field->name);
        self::assertSame(0, $field->default);
        self::assertSame('x', $field->description);
    }

    public function testAFieldNamedTwiceIsRefused(): void
    {
        self::assertSame('schema/compile', self::categoryOf(
            fn () => self::compiled('%{P: %schema{x: :integer, x: :string}}'),
        ));
    }

    public function testTheOldFieldsFormIsRefusedBeingAFieldOfNoKnownType(): void
    {
        self::assertSame('schema/compile', self::categoryOf(
            fn () => self::compiled('%{P: %schema{fields: @ordered %{x: :integer}}}'),
        ));
    }

    // A schema in Dextrin 0.1's syntax says what changed, rather than that
    // a field has no known type -- as the reference.
    public function testA01SchemaSaysItIsOneAndWhatASchemaIsWrittenAsNow(): void
    {
        foreach ([
            '%{P: %schema{fields: @ordered %{x: :integer}}}',
            '%{P: %schema{closed: true, x: :integer}}',
            '%{P: %schema{forbidden: [y], x: :integer}}',
        ] as $old) {
            try {
                self::compiled($old);
            } catch (\JOetjen\Dextrin\Schema\SchemaCompileError $err) {
                self::assertStringContainsString('Dextrin 0.1', $err->getMessage());
                self::assertStringContainsString('%schema(N){field: type, ...}', $err->getMessage());
                continue;
            }
            self::fail("expected {$old} to be refused");
        }
    }

    public function testASchemaMayStillHaveFieldsSoNamed(): void
    {
        self::compiled('%{P: %schema{fields: {:list-of :string}, closed: :boolean, forbidden?: :string}}');
        $this->addToAssertionCount(1);
    }

    public function testVersionsLoadFromSeparateDocumentsAndAreKeptApart(): void
    {
        $registry = self::compiled(
            '%{User: %schema(2){name: :string, email: :string}}',
            self::compiled('%{User: %schema(1){name: :string}}'),
        );
        self::assertSame(['name'], self::version($registry, 'User', 1)->fieldNames());
        self::assertSame(['name', 'email'], self::version($registry, 'User', 2)->fieldNames());
    }

    public function testWithoutAVersionTheLatestIsAnswered(): void
    {
        $registry = self::compiled(
            '%{User: %schema(1){name: :string}}',
            self::compiled('%{User: %schema(2){name: :string, email: :string}}'),
        );
        self::assertSame(2, $registry->fetchStructSchema('User')?->compiled->version);
    }

    public function testLoadingTheSameNameAndVersionTwiceIsRefused(): void
    {
        $registry = self::compiled('%{User: %schema(1){name: :string}}');
        self::assertSame('schema/duplicate-version', self::categoryOf(
            fn () => self::compiled('%{User: %schema(1){name: :string}}', $registry),
        ));
    }

    public function testReplaceSwapsALoadedVersion(): void
    {
        $registry = self::compiled('%{User: %schema(1){name: :string}}');
        $replacement = self::version(self::compiled('%{User: %schema(1){name: :string, nick?: :string}}'), 'User', 1);
        $swapped = $registry->replaceStructSchema('User', $replacement);
        self::assertSame(['name', 'nick'], self::version($swapped, 'User', 1)->fieldNames());
    }

    public function testReplaceRefusesAVersionThatIsNotLoadedSoATypoNeverAddsOne(): void
    {
        $replacement = self::version(self::compiled('%{User: %schema(4){name: :string}}'), 'User', 4);
        self::assertSame('schema/not-loaded', self::categoryOf(
            fn () => (new Registry())->replaceStructSchema('User', $replacement),
        ));
    }

    public function testAStructLiteralCarriesTheVersionItWasWrittenWith(): void
    {
        $keyed = Dextrin::decode('%P(3){x: 1}');
        $positional = Dextrin::decode('%P(3)[1]');
        self::assertInstanceOf(DXNStruct::class, $keyed);
        self::assertInstanceOf(DXNStruct::class, $positional);
        self::assertSame(3, $keyed->version);
        self::assertSame(3, $positional->version);
    }

    public function testAStructLiteralHasNoneWhenWrittenWithoutOne(): void
    {
        $value = Dextrin::decode('%P{x: 1}');
        self::assertInstanceOf(DXNStruct::class, $value);
        self::assertNull($value->version);
    }

    public function testAStructLiteralIsWrittenBackWithItsVersion(): void
    {
        $value = Dextrin::decode('%P(3){x: 1}');
        self::assertSame('%P(3){x:1}', Dextrin::encode($value));
        self::assertSame('%P(3){x: 1}', Canonical::print($value));
    }

    public function testAVersionThatIsNotAPositiveIntegerIsRefused(): void
    {
        self::assertSame('syntax', self::categoryOf(fn () => Dextrin::decode('%P(0){x: 1}')));
        self::assertSame('syntax', self::categoryOf(fn () => Dextrin::decode('%P(-1){x: 1}')));
    }
}
