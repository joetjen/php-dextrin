<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

/**
 * A struct guarantees its declared fields and may carry more (DXN.md
 * §1.2, §2.3, §4.7). Extra fields are entries in text -- after the items
 * of a positional struct -- and one trailing tag-215 map in binary. An
 * extra never shadows a declared field, its key follows the map-key
 * rule, and `strict: true` refuses extras on a schema-backed struct.
 */
final class ExtraFieldsTest extends TestCase
{
    private const USER = '%{User: %schema(2){name: :string, email?: :string}}';

    private static function loaded(): Registry
    {
        return Schema::compile(Dextrin::decode(self::USER), new Registry());
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

    public function testAStructWithNoSchemaKeepsTheEntriesAfterAPositionalStructsItemsAsExtraFields(): void
    {
        $value = Dextrin::decode('%P[1 2 nick: "b" 3 => "x"]');
        self::assertInstanceOf(DXNStruct::class, $value);
        self::assertSame([1, 2], $value->values());
        self::assertSame([['nick', 'b'], [3, 'x']], $value->extra);
    }

    public function testItKeepsAKeyedEntryWhoseKeyIsNotANameAsAnExtraField(): void
    {
        $value = Dextrin::decode('%P{x: 1, 3 => "y"}');
        self::assertInstanceOf(DXNStruct::class, $value);
        self::assertSame([['x', 1]], $value->pairs());
        self::assertSame([[3, 'y']], $value->extra);
    }

    public function testItIsWrittenBackWithItsExtraFieldsInText(): void
    {
        $value = Dextrin::decode('%P[1 2 nick: "b" 3 => :x]');
        self::assertSame('%P[1,2,nick:"b",3=>:x]', Dextrin::encode($value));
        self::assertSame('%P[1 2 nick: "b" 3 => :x]', Canonical::print($value));
    }

    public function testAndInBinaryAsOneTrailingTag215Map(): void
    {
        $value = DXNStruct::positional('P', [1], null, [['nick', 'b']]);
        self::assertTrue($value->equals(Dextrin::decodeBinary(Dextrin::encodeBinary($value))));
    }

    public function testItRefusesAnItemAfterAnEntry(): void
    {
        self::assertSame('syntax', self::categoryOf(fn () => Dextrin::decode('%P[1 nick: "b" 2]')));
    }

    public function testItRefusesAnExtraFieldWhoseKeyIsNotAMapKey(): void
    {
        self::assertSame('key/not-allowed', self::categoryOf(fn () => Dextrin::decode('%P[1 1.5 => 2]')));
    }

    public function testAStructWithASchemaKeepsANamedFieldItsSchemaDoesNotDeclareAsAnExtraField(): void
    {
        $value = Dextrin::decode('%User(2){name: "a", nick: "b"}', self::loaded());
        self::assertTrue(DXNStruct::keyed('User', [['name', 'a']], 2, [['nick', 'b']])->equals($value));
    }

    public function testAndTheEntriesAfterAPositionalStructsItems(): void
    {
        $value = Dextrin::decode('%User(2)["a" "e" nick: "b"]', self::loaded());
        self::assertTrue(DXNStruct::keyed('User', [['name', 'a'], ['email', 'e']], 2, [['nick', 'b']])->equals($value));
    }

    public function testItHandsItsExtraFieldsToTheMaterializerWithTheDeclaredOnes(): void
    {
        $registry = self::loaded()->putStructMaterializer(
            'User',
            static fn (string $name, int $version, array $fields): array => $fields,
        );
        self::assertSame(['name' => 'a', 'nick' => 'b'], Dextrin::decode('%User(2){name: "a", nick: "b"}', $registry));
    }

    public function testItCarriesThemThroughBinaryInTheTag215Item(): void
    {
        $registry = self::loaded();
        $value = DXNStruct::keyed('User', [['name', 'a'], ['email', 'e']], 2, [['nick', 'b']]);
        self::assertTrue($value->equals(Dextrin::decodeBinary(Dextrin::encodeBinary($value, registry: $registry), $registry)));
    }

    public function testItRefusesAnExtraFieldThatShadowsADeclaredOneInText(): void
    {
        self::assertSame('schema/shadowed-field', self::categoryOf(
            fn () => Dextrin::decode('%User(2){name: "a", "name" => "b"}', self::loaded()),
        ));
    }

    public function testAndInBinary(): void
    {
        $bytes = Dextrin::encodeBinary(DXNStruct::positional('User', ['a', 'e'], 2, [['name', 'b']]));
        self::assertSame('schema/shadowed-field', self::categoryOf(fn () => Dextrin::decodeBinary($bytes, self::loaded())));
    }

    public function testAndOnTheWayOut(): void
    {
        $shadowing = DXNStruct::keyed('User', [['name', 'a']], 2, [['name', 'b']]);
        $this->expectException(DXNError::class);
        Dextrin::encode($shadowing, registry: self::loaded());
    }

    public function testItRefusesEveryExtraFieldWhenTheDecodeIsStrict(): void
    {
        self::assertSame('schema/undeclared-field', self::categoryOf(
            fn () => Dextrin::decode('%User(2){name: "a", nick: "b"}', self::loaded(), strict: true),
        ));
        self::assertInstanceOf(DXNStruct::class, Dextrin::decode('%User(2){name: "a"}', self::loaded(), strict: true));
    }
}
