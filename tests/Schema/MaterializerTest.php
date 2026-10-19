<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

final class MaterializerTestUser
{
    public function __construct(public ?string $name = null, public ?string $email = null)
    {
    }
}

/**
 * One materializer per schema, `(schemaName, version, fields)` with
 * fields keyed by name, called for every decoded struct of its schema
 * whatever the version, its result validated against the latest schema
 * (DXN.md §4.8). Without one, the latest version comes back as a generic
 * {@see DXNStruct}.
 */
final class MaterializerTest extends TestCase
{
    private const V1 = '%{User: %schema{name: :string}}';
    private const V2 = '%{User: %schema(2){name: :string, email?: :string}}';
    private const EVENT = '%{Event: %schema{kind: :keyword, user: User}}';

    private static function loaded(string ...$sources): Registry
    {
        $registry = new Registry();
        foreach ($sources as $source) {
            $registry = Schema::compile(Dextrin::decode($source), $registry);
        }
        return $registry;
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

    public function testWithoutOneTheLatestVersionComesBackAsAGenericStructAtTheLatestVersion(): void
    {
        $value = Dextrin::decode('%User{name: "a"}', self::loaded(self::V1, self::V2));
        self::assertTrue(DXNStruct::keyed('User', [['name', 'a']], 2)->equals($value));
    }

    public function testItsFieldsAreInTheSchemasOrderAndAnAbsentOptionalFieldStaysAbsent(): void
    {
        $value = Dextrin::decode('%User(2){email: "e", name: "a"}', self::loaded(self::V2));
        self::assertInstanceOf(DXNStruct::class, $value);
        self::assertSame([['name', 'a'], ['email', 'e']], $value->pairs());
        $absent = Dextrin::decode('%User(2){name: "a"}', self::loaded(self::V2));
        self::assertInstanceOf(DXNStruct::class, $absent);
        self::assertSame([['name', 'a']], $absent->pairs());
    }

    public function testAndItIsWrittenBackExactlyAsItWasRead(): void
    {
        $registry = self::loaded(self::V2);
        $user = Dextrin::decode('%User(2){name: "a", email: "e"}', $registry);
        self::assertSame('%User(2){name:"a",email:"e"}', Dextrin::encode($user, registry: $registry));
    }

    public function testAMaterializerIsCalledWithTheSchemasNameTheVersionAndTheFields(): void
    {
        $calls = [];
        $registry = self::loaded(self::V2)->putStructMaterializer(
            'User',
            static function (string $name, int $version, array $fields) use (&$calls): array {
                $calls[] = [$name, $version, $fields];
                return ['name' => $fields['name']];
            },
        );
        self::assertSame(['name' => 'a'], Dextrin::decode('%User(2){name: "a"}', $registry));
        self::assertSame([['User', 2, ['name' => 'a']]], $calls);
    }

    public function testItUpgradesAnOlderVersionAndIsCalledForEveryVersionAlike(): void
    {
        $registry = self::loaded(self::V1, self::V2)->putStructMaterializer(
            'User',
            static fn (string $name, int $version, array $fields): array => $fields + ['email' => "v{$version}@example.com"],
        );
        self::assertSame(['name' => 'a', 'email' => 'v1@example.com'], Dextrin::decode('%User(1){name: "a"}', $registry));
        self::assertSame(['name' => 'a', 'email' => 'v2@example.com'], Dextrin::decode('%User(2){name: "a"}', $registry));
    }

    public function testItHasItsResultValidatedAgainstTheLatestSchema(): void
    {
        $registry = self::loaded(self::V1, self::V2)->putStructMaterializer(
            'User',
            static fn (string $name, int $version, array $fields): array => ['email' => 'e'],
        );
        self::assertSame('schema/materializer', self::categoryOf(fn () => Dextrin::decode('%User(1){name: "a"}', $registry)));
    }

    public function testAMaterializerThatThrowsMakesTheDecodeRefuse(): void
    {
        $registry = self::loaded(self::V2)->putStructMaterializer(
            'User',
            static fn (string $name, int $version, array $fields): never => throw new \RuntimeException('not today'),
        );
        self::assertSame('schema/materializer', self::categoryOf(fn () => Dextrin::decode('%User(2){name: "a"}', $registry)));
    }

    public function testItRunsBottomUpSoAnOuterMaterializerSeesTheInnerOneAlreadyBuilt(): void
    {
        $seen = [];
        $registry = self::loaded(self::V2, self::EVENT)
            ->putStructModule('User', MaterializerTestUser::class)
            ->putStructMaterializer(
                'User',
                static fn (string $name, int $version, array $fields): MaterializerTestUser
                    => new MaterializerTestUser($fields['name'], $fields['email'] ?? null),
            )
            ->putStructMaterializer('Event', static function (string $name, int $version, array $fields) use (&$seen): array {
                $seen[] = $fields['user'];
                return $fields;
            });
        Dextrin::decode('%Event{kind: :login, user: %User(2){name: "a", email: "e"}}', $registry);
        self::assertEquals([new MaterializerTestUser('a', 'e')], $seen);
    }

    public function testItWorksTheSameFromBinary(): void
    {
        $registry = self::loaded(self::V2)->putStructMaterializer(
            'User',
            static fn (string $name, int $version, array $fields): array => $fields + ['version' => $version],
        );
        $bytes = Dextrin::encodeBinary(DXNStruct::positional('User', ['a', 'e'], 2));
        self::assertSame(['name' => 'a', 'email' => 'e', 'version' => 2], Dextrin::decodeBinary($bytes, $registry));
    }
}
