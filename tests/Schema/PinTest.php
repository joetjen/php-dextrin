<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Document;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\Equality;
use PHPUnit\Framework\TestCase;

/**
 * G11 and Q4 of dextrin's design/typespec.md: a field pinned to one
 * version of a schema or a named type, written `Pet(1)` -- text sugar for
 * `{:at Pet 1}` -- and named types with versions of their own,
 * `%type(N)[T]` (Way B). Unpinned, a reference means the latest. As the
 * reference's pin_test.exs.
 */
final class PinTest extends TestCase
{
    private const PETS = [
        '%{Pet: %schema(1){name: :string, colour: :string}}',
        '%{Pet: %schema(2){name: :string, color: :string}}',
    ];

    private const COLORS = [
        '%{Color: %type(1)[{:enum :red :green}]}',
        '%{Color: %type(2)[{:enum :red :green :blue}]}',
    ];

    /** @param list<string> $sources */
    private static function loaded(array $sources, ?Registry $registry = null): Registry
    {
        $registry ??= new Registry();
        foreach ($sources as $source) {
            $registry = Schema::compile(Dextrin::decode($source), $registry);
        }
        return $registry;
    }

    /**
     * The category and message compiling the last of `$sources` over the
     * others fails with.
     *
     * @param list<string> $sources
     * @return array{0: ?string, 1: string}
     */
    private static function compileError(array $sources): array
    {
        $last = array_pop($sources);
        $registry = self::loaded($sources);
        try {
            Schema::compile(Dextrin::decode($last), $registry);
        } catch (\Throwable $err) {
            return [self::categoryOf($err), $err->getMessage()];
        }
        self::fail("{$last} should be refused");
    }

    private static function categoryOf(\Throwable $err): ?string
    {
        return match (true) {
            $err instanceof HasCategory => $err->category(),
            $err instanceof DXNError => $err->category,
            default => null,
        };
    }

    /** The category `$run` fails with, or `ok` when it does not. */
    private static function category(callable $run): string
    {
        try {
            $run();
        } catch (\Throwable $err) {
            return self::categoryOf($err) ?? $err::class;
        }
        return 'ok';
    }

    private static function pets(string $owner): Registry
    {
        $registry = self::loaded(self::PETS)->putStructMaterializer(
            'Pet',
            static fn (string $name, int $version, array $fields): array => $version === 1
                ? ['name' => $fields['name'], 'color' => $fields['colour']]
                : $fields,
        );
        return self::loaded([$owner], $registry);
    }

    // ---- Name(N) in DXN text --------------------------------------------------------

    public function testNameNReadsAsTheTupleAtNameNWhereverItStands(): void
    {
        $at = new DXNTuple([new DXNKeyword('at'), new DXNSymbol('Pet'), 1]);
        self::assertTrue($at->equals(Dextrin::decode('Pet(1)')));
        self::assertTrue($at->equals(Dextrin::decode('{:at Pet 1}')));
        self::assertTrue((new DXNList([$at, 2]))->equals(Dextrin::decode('[Pet(1) 2]')));
        self::assertSame(['acme/Pet', 3], DXNTuple::pinned(Dextrin::decode('acme/Pet(3)')));
    }

    public function testItIsWrittenBackAsNameNPlainPrettyAndCanonical(): void
    {
        $value = Dextrin::decode('%{pet: {:at acme.zoo/Pet 2}, n: 1}');
        self::assertStringContainsString('acme.zoo/Pet(2)', Dextrin::encode($value));
        self::assertStringContainsString('acme.zoo/Pet(2)', Dextrin::encode($value, pretty: true));
        self::assertStringContainsString('acme.zoo/Pet(2)', Canonical::print($value));
    }

    public function testAnyOtherAtTupleIsAnOrdinaryTupleWrittenAsOne(): void
    {
        foreach (['{:at Pet 0}', '{:at "Pet" 1}', '{:at Pet 1 2}', '{:at Pet}'] as $text) {
            self::assertNull(DXNTuple::pinned(Dextrin::decode($text)), $text);
            self::assertStringStartsWith('{', Dextrin::encode(Dextrin::decode($text)), $text);
        }
    }

    public function testTheVersionIsAPositiveInteger(): void
    {
        try {
            Dextrin::decode('Pet(0)');
            self::fail('Pet(0) should be refused');
        } catch (DXNError $err) {
            self::assertStringContainsString('positive', $err->getMessage());
            self::assertSame('syntax', $err->category);
        }
        self::assertSame('syntax', self::category(static fn () => Dextrin::decode('Pet(x)')));
    }

    public function testTheLosslessFormatterKeepsItAsWritten(): void
    {
        self::assertStringContainsString('pet: Pet(1)', Document::parse('%{pet:   Pet(1)}')->format());
    }

    // ---- a field pinned to a schema version ------------------------------------------

    public function testAPinnedFieldTakesThatVersionOnlyAndKeepsItAsWrittenNotUpgraded(): void
    {
        $r = self::pets('%{Owner: %schema{pet: Pet(1)}}');
        $owner = Dextrin::decode('%Owner{pet: %Pet(1){name: "a", colour: "red"}}', $r);
        self::assertInstanceOf(DXNStruct::class, $owner);
        $pet = $owner->pairs()[0][1];
        self::assertInstanceOf(DXNStruct::class, $pet);
        self::assertSame(['Pet', 1], [$pet->name, $pet->version]);
        self::assertContains(['colour', 'red'], $pet->pairs());

        foreach (['%Owner{pet: %Pet(2){name: "a", color: "red"}}', '%Owner{pet: %Pet{name: "a", color: "red"}}'] as $text) {
            self::assertSame('schema/type-mismatch', self::category(static fn () => Dextrin::decode($text, $r)), $text);
        }
    }

    public function testItNeedsNoMaterializerTheStructIsTakenAsRead(): void
    {
        $r = self::loaded([...self::PETS, '%{Owner: %schema{pet: Pet(1)}, Keeper: %schema{pet: Pet}}']);
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%Owner{pet: %Pet(1){name: "a", colour: "red"}}', $r)));
        self::assertSame('schema/no-materializer', self::category(static fn () => Dextrin::decode('%Keeper{pet: %Pet(1){name: "a", colour: "red"}}', $r)));
        self::assertSame('schema/no-materializer', self::category(static fn () => Dextrin::decode('[%Pet(1){name: "a", colour: "red"}]', $r)));
        $binary = Dextrin::encodeBinary(DXNStruct::keyed('Pet', [['name', 'a'], ['colour', 'red']], 1), registry: $r);
        self::assertSame('schema/no-materializer', self::category(static fn () => Dextrin::decodeBinary($binary, $r)));
        self::assertSame('schema/no-materializer', self::category(static fn () => Document::parse('%Pet(1){name: "a", colour: "red"}', $r)));
    }

    public function testAStructThatCannotEvenBeReadAsWrittenReportsTheMissingMaterializer(): void
    {
        $r = self::loaded([...self::PETS, '%{Owner: %schema{pet: Pet(1)}}']);
        self::assertSame('schema/no-materializer', self::category(static fn () => Dextrin::decode('%Owner{pet: %Pet(1){name: 1}}', $r)));
    }

    public function testUnpinnedAnyLoadedVersionIsTakenAndUpgradedToTheLatest(): void
    {
        $r = self::pets('%{Owner: %schema{pet: Pet}}');
        $owner = Dextrin::decode('%Owner{pet: %Pet(1){name: "a", colour: "red"}}', $r);
        self::assertInstanceOf(DXNStruct::class, $owner);
        self::assertSame(['name' => 'a', 'color' => 'red'], $owner->pairs()[0][1]);
    }

    public function testAPinnedFieldIsWrittenAtThatVersion(): void
    {
        $r = self::pets('%{Owner: %schema{pet: Pet(1)}}');
        $pet = DXNStruct::keyed('Pet', [['name', 'a'], ['colour', 'red']], 1);
        self::assertStringContainsString('%Pet(1){', Dextrin::encode(DXNStruct::keyed('Owner', [['pet', $pet]]), registry: $r));
        $wrong = DXNStruct::keyed('Owner', [['pet', $pet->withVersion(2)]]);
        self::assertNotSame('ok', self::category(static fn () => Dextrin::encode($wrong, registry: $r)));
    }

    public function testAtPet1AndPet1AreTheSameField(): void
    {
        $a = self::pets('%{Owner: %schema{pet: Pet(1)}}')->fetchStructSchema('Owner');
        $b = self::pets('%{Owner: %schema{pet: {:at Pet 1}}}')->fetchStructSchema('Owner');
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertEquals($a->compiled->fields, $b->compiled->fields);
    }

    public function testARefusedPinnedFieldSaysWhichVersionItExpected(): void
    {
        $r = self::pets('%{Owner: %schema{pet: Pet(1)}}');
        try {
            Dextrin::decode('%Owner{pet: %Pet(2){name: "a", color: "red"}}', $r);
            self::fail('expected a refusal');
        } catch (DXNError $err) {
            self::assertStringContainsString('expected Pet(1), got Pet(2)', $err->getMessage());
        }
    }

    public function testAnOlderVersionCheckedOnItsOwnWithNoMaterializerIsRefused(): void
    {
        $r = self::loaded(self::PETS);
        $pet = DXNStruct::keyed('Pet', [['name', 'a'], ['colour', 'red']], 1);
        self::assertSame('schema/no-materializer', self::category(static fn () => Schema::check($pet, $r, 'Pet')));
    }

    public function testAPinnedVariantIsNamedByItsPinInDiscriminatorMessages(): void
    {
        [$category, $message] = self::compileError([...self::PETS, '%{P: {:doc {:one-of Pet(1) Pet(2)} %{discriminator: :kind}}}']);
        self::assertSame('schema/compile', $category);
        self::assertStringContainsString('Pet(1)', $message);
    }

    // ---- a named type with versions (%type(N)) ---------------------------------------

    public function testAReferenceMeansTheLatestAPinMeansThatVersion(): void
    {
        $r = self::loaded([...self::COLORS, '%{Car: %schema{paint: Color}, OldCar: %schema{paint: Color(1)}}']);
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%Car{paint: :blue}', $r)));
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%OldCar{paint: :red}', $r)));
        self::assertSame('schema/type-mismatch', self::category(static fn () => Dextrin::decode('%OldCar{paint: :blue}', $r)));
    }

    public function testAPlainDefinitionIsVersion1(): void
    {
        $r = self::loaded(['%{Color: {:enum :red}}', '%{Color: %type(2)[{:enum :red :blue}]}']);
        self::assertTrue(Schema::check(new DXNKeyword('blue'), $r, 'Color'));
        $r = self::loaded(['%{Car: %schema{paint: Color(1)}}'], $r);
        self::assertSame('schema/type-mismatch', self::category(static fn () => Dextrin::decode('%Car{paint: :blue}', $r)));
    }

    public function testEachVersionOnceTheSameDefinitionAgainIsNoChange(): void
    {
        [$category, $message] = self::compileError([...self::COLORS, '%{Color: %type(2)[{:enum :red}]}']);
        self::assertSame('schema/duplicate-version', $category);
        self::assertStringContainsString('Color', $message);
        self::assertInstanceOf(Registry::class, self::loaded([...self::COLORS, '%{Color: %type(2)[{:enum :red :green :blue}]}']));
    }

    public function testAPinToAVersionNotLoadedIsACompileError(): void
    {
        [$category, $message] = self::compileError([...self::COLORS, '%{Car: %schema{paint: Color(3)}}']);
        self::assertSame('schema/compile', $category);
        self::assertStringContainsString('Color(3)', $message);
    }

    public function testASiblingPinnedAtItsOwnVersionStaysByName(): void
    {
        $r = self::loaded(['%{Color: %type(2)[{:enum :red :blue}], Car: %schema{paint: Color(2)}}']);
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%Car{paint: :blue}', $r)));
    }

    public function testTypeTakesOneItemTheTypeAndAVersion(): void
    {
        foreach (['%{C: %type(1)[:string :integer]}', '%{C: %type[:string]}', '%{C: %type(1){a: 1}}'] as $text) {
            self::assertSame('schema/compile', self::category(static fn () => Schema::compile(Dextrin::decode($text), new Registry())), $text);
        }
    }

    public function testOneVersionCanBeDeprecated(): void
    {
        $r = self::loaded([
            '%{Color: {:doc %type(1)[{:enum :red :green}] %{deprecated: "use Color(2)"}}}',
            '%{Color: %type(2)[{:enum :red :green :blue}]}',
            '%{Car: %schema{paint: Color}, OldCar: %schema{paint: Color(1)}}',
        ]);
        self::assertSame(
            [['name' => 'Color', 'version' => 1, 'message' => 'use Color(2)', 'category' => 'schema/deprecated', 'at' => 'OldCar']],
            $r->warnings(),
        );
    }

    public function testTheRegistryKeepsEveryVersionTheLatestIsTheAlias(): void
    {
        $r = self::loaded(self::COLORS);
        self::assertNotNull($r->fetchTypeVersion('Color', 1));
        self::assertNotNull($r->fetchTypeVersion('Color', 2));
        self::assertNull($r->fetchTypeVersion('Color', 3));
        self::assertSame($r->fetchTypeVersion('Color', 2), $r->fetchTypeAlias('Color'));
        self::assertTrue(Equality::dxnEquals(DXNTuple::pin('Color', 2), Dextrin::decode('Color(2)')));
    }
}
