<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

/**
 * A struct is read according to the version it was written with, set
 * against the versions loaded (DXN.md §4.8). Materialized fields carry
 * MapBuilder's string-key marker, as every materialized field does.
 */
final class ReadingAcrossVersionsTest extends TestCase
{
    private const V1 = '%{User: %schema{name: :string}}';
    private const V2 = '%{User: %schema(2){name: :string, email?: :string}}';
    private const V3 = '%{User: %schema(3){name: :string, email?: :string, nick?: :string}}';

    private static function loaded(string ...$sources): Registry
    {
        $registry = new Registry();
        foreach ($sources as $source) {
            $registry = Schema::compile(Dextrin::decode($source), $registry);
        }
        return $registry;
    }

    private static function upgrading(Registry $registry): Registry
    {
        return $registry->putStructMaterializer('User', static fn (array $fields): array => ['upgraded' => $fields]);
    }

    private static function categoryOf(\Closure $refused): ?string
    {
        try {
            $refused();
        } catch (DXNError $err) {
            self::assertInstanceOf(DXNError::class, $err);
            return $err->category;
        }
        self::fail('expected a refusal');
    }

    private static function binary(DXNStruct $struct): string
    {
        return Dextrin::encodeBinary($struct);
    }

    public function testTheLatestVersionIsReadWithExactlyThatSchema(): void
    {
        self::assertSame(
            ["\0name" => 'a', "\0email" => null, "\0nick" => null],
            Dextrin::decode('%User(3){name: "a"}', self::loaded(self::V2, self::V3)),
        );
    }

    public function testAndItsItemCountIsExact(): void
    {
        self::categoryOf(fn () => Dextrin::decode('%User(3)["a"]', self::loaded(self::V2, self::V3)));
    }

    public function testAnOlderVersionGoesToTheMaterializerWithTheFieldsItsOwnSchemaNames(): void
    {
        self::assertSame(
            ['upgraded' => ['name' => 'a', 'email' => null]],
            Dextrin::decode('%User(2){name: "a"}', self::upgrading(self::loaded(self::V2, self::V3))),
        );
    }

    public function testAndWithoutOneIsRefusedRatherThanReadAsIfItWereTheLatest(): void
    {
        self::assertSame('schema/no-materializer', self::categoryOf(
            fn () => Dextrin::decode('%User(2){name: "a"}', self::loaded(self::V2, self::V3)),
        ));
    }

    public function testAndTheSameGoesForBinary(): void
    {
        $bytes = self::binary(DXNStruct::positional('User', ['a', null], 2));
        self::assertSame('schema/no-materializer', self::categoryOf(
            fn () => Dextrin::decodeBinary($bytes, self::loaded(self::V2, self::V3)),
        ));
    }

    public function testAnOlderVersionThatIsNotLoadedIsRefusedEvenWithAMaterializer(): void
    {
        $registry = self::upgrading(self::loaded(self::V2, self::V3));
        self::assertSame('schema/unknown-version', self::categoryOf(
            fn () => Dextrin::decode('%User(1){name: "a"}', $registry),
        ));
        $bytes = self::binary(DXNStruct::positional('User', ['a'], 1));
        self::assertSame('schema/unknown-version', self::categoryOf(fn () => Dextrin::decodeBinary($bytes, $registry)));
    }

    public function testANewerVersionIsReadAgainstTheLatestMatchingTextByName(): void
    {
        self::assertSame(
            ["\0name" => 'a', "\0email" => null],
            Dextrin::decode('%User(3){nick: "n", name: "a"}', self::loaded(self::V1, self::V2)),
        );
    }

    public function testAndAFieldTheLatestRequiresMustStillBeThere(): void
    {
        self::categoryOf(fn () => Dextrin::decode('%User(3){email: "e"}', self::loaded(self::V1, self::V2)));
    }

    public function testAndBinaryAssumesPositionsAreUnchangedDroppingTheItemsBeyondItsOwn(): void
    {
        $bytes = self::binary(DXNStruct::positional('User', ['a', 'e', 'n'], 3));
        self::assertSame(["\0name" => 'a', "\0email" => 'e'], Dextrin::decodeBinary($bytes, self::loaded(self::V1, self::V2)));
    }

    public function testAndFewerItemsLeaveTheOptionalFieldsAbsent(): void
    {
        $bytes = self::binary(DXNStruct::positional('User', ['a'], 3));
        self::assertSame(["\0name" => 'a', "\0email" => null], Dextrin::decodeBinary($bytes, self::loaded(self::V1, self::V2)));
    }

    /** The documented risk: a reordering is misread, without an error, whenever the types agree. */
    public function testAndAReorderingIsMisreadWithoutAnErrorWhenTheTypesAgree(): void
    {
        $bytes = self::binary(DXNStruct::positional('User', ['e', 'a'], 3));
        self::assertSame(["\0name" => 'e', "\0email" => 'a'], Dextrin::decodeBinary($bytes, self::loaded(self::V1, self::V2)));
    }
}
