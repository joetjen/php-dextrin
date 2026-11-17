<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

final class VersionedPoint
{
    public function __construct(public ?int $x = null, public ?int $y = null)
    {
    }
}

/**
 * Every struct written against a schema carries the version it was
 * written with -- `%Point(2){...}` in text and the type name `"Point(2)"`
 * in binary -- and that version is the latest one loaded, unless the
 * struct says a loaded version of its own (DXNS.md §8, §5c).
 */
final class VersionsInDocumentsTest extends TestCase
{
    private const V1 = '%{Point: %schema{x: :integer}}';
    private const V2 = '%{Point: %schema(2){x: :integer, y?: :integer}}';

    private static function loaded(string ...$sources): Registry
    {
        $registry = new Registry();
        foreach ($sources as $source) {
            $registry = Schema::compile(Dextrin::decode($source), $registry);
        }
        return $registry->putStructModule('Point', VersionedPoint::class);
    }

    private static function categoryOf(\Closure $refused): ?string
    {
        try {
            $refused();
        } catch (DXNError $err) {
            return $err->category;
        }
        self::fail('expected a refusal');
    }

    public function testAStructWrittenAgainstASchemaCarriesTheLatestVersion(): void
    {
        self::assertSame(
            '%Point(2){x:1,y:2}',
            Dextrin::encode(new VersionedPoint(1, 2), registry: self::loaded(self::V1, self::V2)),
        );
    }

    public function testItCarriesVersionOneWhenThatIsTheOnlyOneSoAReaderNeverHasToGuess(): void
    {
        // `y`, which version 1 does not declare, is the class's own
        // property, so it travels as an extra field.
        self::assertSame('%Point(1){x:1,y:nil}', Dextrin::encode(new VersionedPoint(1), registry: self::loaded(self::V1)));
    }

    public function testItCarriesItWhenLaidOutOverSeveralLines(): void
    {
        $text = Dextrin::encode(new VersionedPoint(1, 2), pretty: true, registry: self::loaded(self::V1, self::V2));
        self::assertStringStartsWith('%Point(2){', $text);
    }

    public function testItCarriesItInBinaryAsTheTypeName(): void
    {
        $back = Dextrin::decodeBinary(Dextrin::encodeBinary(new VersionedPoint(1, 2), registry: self::loaded(self::V1, self::V2)));
        self::assertInstanceOf(DXNStruct::class, $back);
        self::assertSame('Point', $back->name);
        self::assertSame(2, $back->version);
    }

    /**
     * Until G11 (2026-11-17) every struct was written as the latest,
     * whatever version it said: there was no way to write an older one. A
     * field pinned to a version (`Pet(1)`) needs that way, so a struct
     * saying a loaded version keeps it; one saying none, or one not
     * loaded, is written as the latest as before.
     */
    public function testItIsWrittenWithTheVersionItSaysWhenThatOneIsLoadedElseTheLatest(): void
    {
        $registry = self::loaded(self::V1, self::V2);
        self::assertSame('%Point(1){x:1}', Dextrin::encode(DXNStruct::keyed('Point', [['x', 1]], 1), registry: $registry));
        self::assertSame('%Point(2){x:1}', Dextrin::encode(DXNStruct::keyed('Point', [['x', 1]], 7), registry: $registry));
    }

    public function testItGoesToBinaryInTheSchemasFieldOrderWhateverOrderItsKeysCameIn(): void
    {
        $struct = DXNStruct::keyed('Point', [['y', 2], ['x', 1]]);
        $back = Dextrin::decodeBinary(Dextrin::encodeBinary($struct, registry: self::loaded(self::V1, self::V2)));
        self::assertInstanceOf(DXNStruct::class, $back);
        self::assertSame([1, 2], $back->values());
    }

    public function testAStructWithNoSchemaKeepsItsOwnVersionInBinary(): void
    {
        $back = Dextrin::decodeBinary(Dextrin::encodeBinary(DXNStruct::positional('P', [1], 3)));
        self::assertTrue(DXNStruct::positional('P', [1], 3)->equals($back));
    }

    public function testAndHasNoneWhenItWasGivenNone(): void
    {
        $back = Dextrin::decodeBinary(Dextrin::encodeBinary(DXNStruct::positional('P', [1])));
        self::assertInstanceOf(DXNStruct::class, $back);
        self::assertNull($back->version);
    }

    public function testABinaryTypeNameWhoseVersionIsNotAPositiveIntegerIsRefused(): void
    {
        $bytes = Dextrin::encodeBinary(DXNStruct::positional('P(0)', [1]));
        self::assertSame('binary', self::categoryOf(fn () => Dextrin::decodeBinary($bytes)));
    }

    public function testAnUntaggedStructIsReadAsTheLatestVersion(): void
    {
        $registry = self::loaded(self::V1, '%{Point: %schema(2){x: :integer, y: :integer}}');
        // A refusal: version 2 requires `y`. Schema violations get their
        // own categories later, so only the refusal is checked here.
        self::categoryOf(fn () => Dextrin::decode('%Point{x: 1}', $registry));
        self::assertTrue(DXNStruct::keyed('Point', [['x', 1], ['y', 2]], 2)->equals(Dextrin::decode('%Point{x: 1, y: 2}', $registry)));
    }

    /**
     * Until G11 (2026-11-17) a struct reference named no version and
     * `User(2)` was a syntax error. Now `User(2)` reads as `{:at User 2}`,
     * a reference pinned to version 2 (DXNS.md §5c, PinTest).
     */
    public function testAStructReferenceMayNameAVersionUser2PinsVersion2(): void
    {
        $doc = Dextrin::decode('%{P: %schema{u: User(2)}}');
        self::assertIsArray($doc);
        $schema = $doc['P'];
        self::assertInstanceOf(DXNStruct::class, $schema);
        self::assertSame(['User', 2], \JOetjen\Dextrin\Value\DXNTuple::pinned($schema->pairs()[0][1]));
    }
}
