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
 * in binary -- and that version is always the latest one loaded
 * (DXNS.md §8).
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

    public function testItIsWrittenAsTheLatestEvenWhenItSaysAnOlderOne(): void
    {
        self::assertSame(
            '%Point(2){x:1}',
            Dextrin::encode(DXNStruct::keyed('Point', [['x', 1]], 1), registry: self::loaded(self::V1, self::V2)),
        );
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

    public function testAVersionInATypeExpressionIsASyntaxError(): void
    {
        self::assertSame('syntax', self::categoryOf(fn () => Dextrin::decode('%{P: %schema{u: User(2)}}')));
    }
}
