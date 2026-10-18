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
 * B9 of `design/implementation-plan.md`: every schema violation carries
 * its DXN.md §5 category -- decoding text, decoding binary and encoding
 * alike -- instead of the `syntax` or `binary` of whatever channel it
 * travelled on, or none at all.
 */
final class ErrorCategoriesTest extends TestCase
{
    private const USER = '%{User: %schema(2){name: :string, email?: :string}}';

    private static function loaded(): Registry
    {
        return Schema::compile(Dextrin::decode(self::USER), new Registry());
    }

    private static function categoryOf(\Closure $failing): ?string
    {
        try {
            $failing();
        } catch (DXNError $err) {
            return $err->category;
        }
        self::fail('expected a DXNError');
    }

    public function testDecodingTextAMissingRequiredFieldIsSchemaMissingField(): void
    {
        self::assertSame('schema/missing-field', self::categoryOf(static fn () => Dextrin::decode('%User{email: "a"}', self::loaded())));
    }

    public function testAndSoItIsWhenANewerVersionIsReadAgainstTheLatest(): void
    {
        self::assertSame('schema/missing-field', self::categoryOf(static fn () => Dextrin::decode('%User(3){email: "a"}', self::loaded())));
    }

    public function testDecodingTextAValueOfTheWrongTypeIsSchemaTypeMismatch(): void
    {
        self::assertSame('schema/type-mismatch', self::categoryOf(static fn () => Dextrin::decode('%User{name: 1}', self::loaded())));
    }

    public function testAndSoItIsInsideAnotherValue(): void
    {
        self::assertSame('schema/type-mismatch', self::categoryOf(static fn () => Dextrin::decode('[%User{name: 1}]', self::loaded())));
    }

    public function testDecodingTextAPositionalStructWithTheWrongItemCountIsSchemaFieldCount(): void
    {
        self::assertSame('schema/field-count', self::categoryOf(static fn () => Dextrin::decode('%User(2)["a"]', self::loaded())));
    }

    public function testDecodingBinaryAValueOfTheWrongTypeIsSchemaTypeMismatch(): void
    {
        $bytes = Dextrin::encodeBinary(DXNStruct::positional('User', [1, 'a'], 2));
        self::assertSame('schema/type-mismatch', self::categoryOf(static fn () => Dextrin::decodeBinary($bytes, self::loaded())));
    }

    public function testDecodingBinaryAStructWithTheWrongItemCountIsSchemaFieldCount(): void
    {
        $bytes = Dextrin::encodeBinary(DXNStruct::positional('User', ['a'], 2));
        self::assertSame('schema/field-count', self::categoryOf(static fn () => Dextrin::decodeBinary($bytes, self::loaded())));
    }

    public function testEncodingAValueOfTheWrongTypeIsSchemaTypeMismatch(): void
    {
        $user = DXNStruct::keyed('User', [['name', 1]]);
        self::assertSame('schema/type-mismatch', self::categoryOf(static fn () => Dextrin::encode($user, registry: self::loaded())));
        self::assertSame('schema/type-mismatch', self::categoryOf(static fn () => Dextrin::encodeBinary($user, registry: self::loaded())));
    }

    public function testEncodingAMissingRequiredFieldIsSchemaMissingField(): void
    {
        $user = DXNStruct::keyed('User', [['email', 'a']]);
        self::assertSame('schema/missing-field', self::categoryOf(static fn () => Dextrin::encode($user, registry: self::loaded())));
    }

    public function testEncodingAPositionalStructWithTheWrongItemCountIsSchemaFieldCount(): void
    {
        $user = DXNStruct::positional('User', ['a']);
        self::assertSame('schema/field-count', self::categoryOf(static fn () => Dextrin::encode($user, registry: self::loaded())));
    }

    public function testEncodingAnExtraFieldShadowingADeclaredOneIsSchemaShadowedField(): void
    {
        $user = DXNStruct::positional('User', ['a', 'b'], null, [['name', 'c']]);
        self::assertSame('schema/shadowed-field', self::categoryOf(static fn () => Dextrin::encode($user, registry: self::loaded())));
    }

    public function testEncodingAgainstANamedSchemaToo(): void
    {
        self::assertSame(
            'schema/type-mismatch',
            self::categoryOf(static fn () => Dextrin::encode(['name' => 1], registry: self::loaded(), schema: 'User')),
        );
    }

    public function testAMaterializersRejectedValueIsSchemaMaterializerWhateverWasWrongWithIt(): void
    {
        $registry = self::loaded()->putStructMaterializer('User', static fn () => DXNStruct::keyed('User', [['name', 1]]));
        self::assertSame('schema/materializer', self::categoryOf(static fn () => Dextrin::decode('%User{name: "a"}', $registry)));
    }
}
