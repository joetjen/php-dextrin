<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use PHPUnit\Framework\TestCase;

/**
 * G10 of dextrin's design/typespec.md: "mark Schemas in Dextrin as
 * deprecated, and generate a warning when they are used", and "we need to
 * be able to deprecate a specific version of a schema, not only the whole
 * schema" -- as the reference's deprecation_test.exs.
 *
 * `deprecated:` (DXNS.md §1b) on a version's own entry deprecates that
 * version only. A warning fires when a schema compiles against what is
 * deprecated, and when data goes through it; `deprecations:` says how
 * often, or makes it an error (`schema/deprecated`), and `onDeprecated:`
 * receives each warning in place of `trigger_error()`.
 */
final class DeprecationTest extends TestCase
{
    private const PET_V1 = '%{Pet: {:doc %schema(1){name: :string, colour: :string} %{deprecated: "use Pet(2)"}}}';
    private const PET_V2 = '%{Pet: %schema(2){name: :string, color: :string}}';

    private const OLD = <<<'DXN'
        %{
          Old: {:doc %schema{x: :integer} %{deprecated: true}}
          Holder: %schema{old: Old}
        }
        DXN;

    private const PARTS = <<<'DXN'
        %{
          User: %schema{
            email: :string
            legacy?: {:doc :string %{deprecated: "use email"}}
            color?: {:doc {:enum :red :old-blue} %{values: %{:old-blue => %{deprecated: "use :blue"}}}}
            id?: {:any-of :integer {:doc :string %{deprecated: "ids are integers now"}}}
          }
        }
        DXN;

    /** @var list<array{name: ?string, version: ?int, message: ?string, category: string, at: ?string}> */
    private array $heard = [];

    /** @param list<string> $sources */
    private static function loaded(array $sources, ?Registry $registry = null): Registry
    {
        $registry ??= new Registry();
        foreach ($sources as $source) {
            $registry = Schema::compile(Dextrin::decode($source), $registry);
        }
        return $registry;
    }

    private static function upgraded(Registry $registry): Registry
    {
        return $registry->putStructMaterializer('Pet', static fn (string $name, int $version, array $fields): mixed => $version === 1
            ? ['name' => $fields['name'], 'color' => $fields['colour']]
            : $fields);
    }

    /** Decodes `$text`, every warning kept in {@see self::$heard}. */
    private function decode(string $text, Registry $registry, ?string $deprecations = null): mixed
    {
        return Dextrin::decode($text, $registry, deprecations: $deprecations, onDeprecated: $this->hear(...));
    }

    /** @param array{name: ?string, version: ?int, message: ?string, category: string, at: ?string} $warning */
    private function hear(array $warning): void
    {
        $this->heard[] = $warning;
    }

    /**
     * The warnings heard since the last call.
     *
     * @return list<array{name: ?string, version: ?int, message: ?string, category: string, at: ?string}>
     */
    private function warnings(): array
    {
        $heard = $this->heard;
        $this->heard = [];
        return $heard;
    }

    // ---- one version of a schema --------------------------------------------------

    public function testDataWrittenWithItWarnsNamingTheVersionAndTheReason(): void
    {
        $r = self::upgraded(self::loaded([self::PET_V1, self::PET_V2]));
        $this->decode('%Pet(1){name: "a", colour: "red"}', $r);
        self::assertSame(
            [['name' => 'Pet', 'version' => 1, 'message' => 'use Pet(2)', 'category' => 'schema/deprecated', 'at' => null]],
            $this->warnings(),
        );
    }

    public function testTheOtherVersionsAndDataWithNoVersionDoNot(): void
    {
        $r = self::upgraded(self::loaded([self::PET_V1, self::PET_V2]));
        $this->decode('%Pet(2){name: "a", color: "red"}', $r);
        $this->decode('%Pet{name: "a", color: "red"}', $r);
        self::assertSame([], $this->warnings());
    }

    public function testAReaderUpgradingItToTheLatestStillWarnsOnce(): void
    {
        $r = self::upgraded(self::loaded([self::PET_V1, self::PET_V2]));
        $list = $this->decode('[%Pet(1){name: "a", colour: "red"} %Pet(1){name: "b", colour: "g"}]', $r);
        self::assertCount(2, $list->items);
        self::assertSame([1], array_column($this->warnings(), 'version'));
        $this->decode('%Pet(1){name: "c", colour: "red"}', $r);
        self::assertSame([], $this->warnings());
    }

    public function testOnceIsSharedByCopiesOfTheRegistry(): void
    {
        $r = self::upgraded(self::loaded([self::PET_V1, self::PET_V2]));
        $this->decode('%Pet(1){name: "a", colour: "red"}', $r->putStrict(false));
        $this->decode('%Pet(1){name: "a", colour: "red"}', $r);
        self::assertCount(1, $this->warnings());
    }

    public function testEveryUseWarnsUnderEveryNoneUnderOff(): void
    {
        $r = self::upgraded(self::loaded([self::PET_V1, self::PET_V2]));
        $text = '[%Pet(1){name: "a", colour: "red"} %Pet(1){name: "b", colour: "g"}]';
        $this->decode($text, $r, 'every');
        self::assertCount(2, $this->warnings());
        $this->decode($text, $r, 'off');
        self::assertSame([], $this->warnings());
    }

    public function testIsAnErrorUnderError(): void
    {
        $r = self::upgraded(self::loaded([self::PET_V1, self::PET_V2]));
        try {
            $this->decode('%Pet(1){name: "a", colour: "red"}', $r, 'error');
            self::fail('expected a refusal');
        } catch (DXNError $err) {
            self::assertSame('schema/deprecated', $err->category);
        }
    }

    public function testAnUnknownHowOftenIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Registry())->putDeprecations('sometimes');
    }

    public function testABaseNamingThatVersionWarnsWhenTheSchemaCompilesAnyOtherDoesNot(): void
    {
        $r = self::loaded([self::PET_V1, self::PET_V2]);
        $pinned = self::loaded(['%{Show: %schema includes Pet(1) {judge: :string}}'], $r);
        self::assertSame(
            [['name' => 'Pet', 'version' => 1, 'message' => 'use Pet(2)', 'category' => 'schema/deprecated', 'at' => 'Show']],
            $pinned->warnings(),
        );
        foreach ([
            '%{Show: %schema includes Pet(2) {judge: :string}}',
            '%{Show: %schema includes Pet {judge: :string}}',
            '%{Owner: %schema{pet: Pet}}',
        ] as $text) {
            self::assertSame([], self::loaded([$text], $r)->warnings(), $text);
        }
    }

    public function testItsAnnotationsAreReadByItsVersion(): void
    {
        $r = self::loaded([self::PET_V1, self::PET_V2]);
        self::assertSame(['deprecated' => 'use Pet(2)'], Schema::annotations($r, 'Pet', 1));
        $annotations = Schema::annotations($r, 'Pet', 2);
        self::assertIsArray($annotations);
        self::assertArrayNotHasKey('deprecated', $annotations);
        self::assertNull(Schema::annotations($r, 'Pet', 3));
    }

    // ---- a whole schema -------------------------------------------------------------

    public function testWarnsWhereAnotherSchemaNamesItAndStaysSilentInWhatIsDeprecatedItself(): void
    {
        $r = self::loaded([self::OLD]);
        $warnings = $r->warnings();
        self::assertCount(1, $warnings);
        self::assertSame(['Old', 'Holder'], [$warnings[0]['name'], $warnings[0]['at']]);
        $quiet = self::loaded([
            '%{Old: {:doc %schema{x: :integer} %{deprecated: true}}, Older: {:doc %schema{o: Old} %{deprecated: true}}}',
        ]);
        self::assertSame([], $quiet->warnings());
    }

    public function testDataOfItWarnsWhateverItsVersion(): void
    {
        $this->decode('%Holder{old: %Old{x: 1}}', self::loaded([self::OLD]));
        $warnings = $this->warnings();
        self::assertCount(1, $warnings);
        self::assertSame(['Old', null], [$warnings[0]['name'], $warnings[0]['message']]);
    }

    // ---- a named type ---------------------------------------------------------------

    public function testANamedTypeWarnsWhereASchemaNamesItAndWhenDataGoesThroughIt(): void
    {
        $r = self::loaded(['%{Colour: {:doc {:enum :red :green} %{deprecated: "use Color"}}, Car: %schema{paint: Colour}}']);
        self::assertSame(
            [['name' => 'Colour', 'version' => null, 'message' => 'use Color', 'category' => 'schema/deprecated', 'at' => 'Car']],
            $r->warnings(),
        );
        $this->decode('%Car{paint: :red}', $r);
        self::assertSame(['use Color'], array_column($this->warnings(), 'message'));
    }

    // ---- a field, an enum value and a variant -------------------------------------

    public function testAFieldAnEnumValueAndAVariantWarnOnlyWhenTheValueGoesThroughThem(): void
    {
        $r = self::loaded([self::PARTS]);
        $this->decode('%User{email: "a", color: :red, id: 1}', $r);
        self::assertSame([], $this->warnings());

        $this->decode('%User{email: "a", legacy: "x", color: :old-blue, id: "7"}', $r);
        $messages = array_column($this->warnings(), 'message');
        sort($messages);
        self::assertSame(['ids are integers now', 'use :blue', 'use email'], $messages);
    }

    public function testADeprecatedTypeInsideAStructRefusesItsFieldUnderError(): void
    {
        $r = self::loaded([self::PARTS]);
        try {
            $this->decode('%User{email: "a", legacy: "x"}', $r, 'error');
            self::fail('expected a refusal');
        } catch (DXNError $err) {
            self::assertSame('schema/type-mismatch', $err->category);
            self::assertStringContainsString('legacy', $err->getMessage());
        }
    }

    public function testAnEncodeWarnsAsADecodeDoes(): void
    {
        $value = Dextrin::decode('%Holder{old: %Old{x: 1}}', self::loaded([self::OLD]), deprecations: 'off');
        Dextrin::encode($value, registry: self::loaded([self::OLD]), onDeprecated: $this->hear(...));
        self::assertSame(['Old'], array_column($this->warnings(), 'name'));
    }

    public function testWithoutOnDeprecatedAWarningIsTriggeredAsADeprecation(): void
    {
        $r = self::loaded([self::OLD]);
        $triggered = [];
        set_error_handler(static function (int $level, string $message) use (&$triggered): bool {
            $triggered[] = [$level, $message];
            return true;
        });
        try {
            Dextrin::decode('%Holder{old: %Old{x: 1}}', $r);
        } finally {
            restore_error_handler();
        }
        self::assertSame([[E_USER_DEPRECATED, 'Old(1) is deprecated']], $triggered);
    }

    public function testATypeNamedOnlyInTypedExtrasWarnsToo(): void
    {
        $r = self::loaded(['%{Old: {:doc :string %{deprecated: true}}, H: %schema{n: :integer, * :string => Old}}']);
        self::assertSame([['Old', 'H']], array_map(static fn (array $w): array => [$w['name'], $w['at']], $r->warnings()));
    }

    public function testEncodingWarnsOfTheStructsOwnVersionOnlyNotTheBaseItPassesAs(): void
    {
        $r = self::loaded([self::PET_V1, self::PET_V2, '%{Show: %schema extends Pet(1) {judge: :string}}']);
        $show = \JOetjen\Dextrin\Value\DXNStruct::keyed('Show', [['name', 'a'], ['colour', 'red'], ['judge', 'j']]);
        Dextrin::encode($show, registry: $r, onDeprecated: $this->hear(...));
        self::assertSame([], $this->warnings());
    }

    public function testAnEncodeUnderErrorRefusesADeprecatedStruct(): void
    {
        $r = self::loaded([self::OLD]);
        $value = Dextrin::decode('%Holder{old: %Old{x: 1}}', $r, deprecations: 'off');
        try {
            Dextrin::encode($value, registry: $r, deprecations: 'error');
            self::fail('expected a refusal');
        } catch (DXNError $err) {
            self::assertSame('schema/deprecated', $err->category);
        }
    }

    public function testCompileWarningsComeInTheOrderOfTheEntriesNames(): void
    {
        $r = self::loaded(['%{Old: {:doc :string %{deprecated: true}}, Zed: %schema{o: Old}, Abe: %schema{o: Old}}']);
        self::assertSame(['Abe', 'Zed'], array_column($r->warnings(), 'at'));
    }

    public function testTheAnnotationsOfOneVersionAsTheDocExampleShows(): void
    {
        $registry = Schema::compile(Dextrin::decode('%{Pet: {:doc %schema(1){name: :string} %{deprecated: "use Pet(2)"}}}'), new Registry());
        self::assertSame(['deprecated' => 'use Pet(2)'], Schema::annotations($registry, 'Pet', 1));
    }

    public function testAWarningInWords(): void
    {
        $text = \JOetjen\Dextrin\Schema\Deprecations::text(...);
        self::assertSame('Pet(1) is deprecated: use Pet(2)', $text(['name' => 'Pet', 'version' => 1, 'message' => 'use Pet(2)', 'category' => 'schema/deprecated', 'at' => null]));
        self::assertSame('Show uses Old, which is deprecated', $text(['name' => 'Old', 'version' => null, 'message' => null, 'category' => 'schema/deprecated', 'at' => 'Show']));
        self::assertSame('a deprecated type or value is deprecated: why', $text(['name' => null, 'version' => null, 'message' => 'why', 'category' => 'schema/deprecated', 'at' => null]));
    }
}
