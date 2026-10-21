<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Binary;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

/**
 * `.dxnb` written by php-dextrin 0.1.0 still reads (the migration notes
 * of 0.2.0). The fixtures were written by 0.1.0 itself:
 * `tests/Fixtures/v0.1.0/generate.php`.
 */
final class V010Test extends TestCase
{
    // The 0.1.0 `user.dxns`, as 0.2.0 writes it: the body lists the
    // fields in the order `fields: @ordered %{...}` gave them.
    private const V1 = '%{User: %schema{name: :string, email?: :string, role: %field{type: :keyword, default: :member}}}';
    private const V2 = '%{User: %schema(2){name: :string, email?: :string, role: :keyword, nick?: :string}}';

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/v0.1.0/' . $name);
    }

    private static function loaded(string ...$sources): Registry
    {
        $registry = new Registry();
        foreach ($sources as $source) {
            $registry = Schema::compile(Dextrin::decode($source), $registry);
        }
        return $registry;
    }

    public function testEveryValueReadsBackAsItsTextDecodes(): void
    {
        self::assertSame(
            Canonical::print(Dextrin::decode(self::fixture('values.respelled.dxn'))),
            Canonical::print(Dextrin::decodeBinary(self::fixture('values.dxnb'))),
        );
    }

    public function testAStructHasNoVersionAndReadsAsVersion1OfTheSameSchema(): void
    {
        self::assertEquals(
            DXNStruct::keyed('User', [['name', 'Ann'], ['email', 'ann@example.com'], ['role', Dextrin::decode(':admin')]], 1),
            Dextrin::decodeBinary(self::fixture('user_full.dxnb'), self::loaded(self::V1)),
        );
    }

    public function testWrittenAgainItCarriesItsVersion(): void
    {
        $registry = self::loaded(self::V1);
        $user = Dextrin::decodeBinary(self::fixture('user_full.dxnb'), $registry);

        self::assertStringContainsString('User(1)', Dextrin::encodeBinary($user, $registry));
    }

    public function testOnceAVersion2IsLoadedAStructWithoutAVersionIsReadAsVersion2(): void
    {
        try {
            Dextrin::decodeBinary(self::fixture('user_full.dxnb'), self::loaded(self::V1, self::V2));
        } catch (DXNError $err) {
            self::assertSame('schema/field-count', $err->category);
            return;
        }
        self::fail('expected a refusal');
    }
}
