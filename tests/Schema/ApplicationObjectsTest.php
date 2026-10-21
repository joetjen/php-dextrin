<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

final class ApplicationObjectsTestUser
{
    public string $email;

    public function __construct(public string $name)
    {
    }
}

#[\AllowDynamicProperties]
final class ApplicationObjectsTestLoose
{
    public function __construct(public string $name)
    {
    }
}

/**
 * An application object is encoded the way a struct is (DXNS.md §3,
 * §4.7): a field it does not have -- an uninitialised or undeclared
 * property -- is absent, never nil, and a property its schema does not
 * declare is an extra field, as an Elixir struct's undeclared keys are
 * (Jan, 2026-10-19).
 */
final class ApplicationObjectsTest extends TestCase
{
    private const SCHEMA = '%{User: %schema(2){name: :string, email?: :string}}';

    /** @param class-string $class */
    private static function loaded(string $class): Registry
    {
        return Schema::compile(Dextrin::decode(self::SCHEMA), new Registry())->putStructModule('User', $class);
    }

    public function testAnUninitialisedPropertyIsAbsentAndLeftOutOfText(): void
    {
        self::assertSame(
            '%User(2){name:"a"}',
            Dextrin::encode(new ApplicationObjectsTestUser('a'), registry: self::loaded(ApplicationObjectsTestUser::class)),
        );
    }

    public function testAndSoIsAPropertyTheClassDoesNotHave(): void
    {
        self::assertSame(
            '%User(2){name:"a"}',
            Dextrin::encode(new ApplicationObjectsTestLoose('a'), registry: self::loaded(ApplicationObjectsTestLoose::class)),
        );
    }

    public function testAnAbsentOptionalFieldIsRefusedInBinaryWhichFillsEveryPosition(): void
    {
        try {
            Dextrin::encodeBinary(new ApplicationObjectsTestUser('a'), registry: self::loaded(ApplicationObjectsTestUser::class));
            self::fail('expected a refusal');
        } catch (DXNError $err) {
            self::assertSame('schema/missing-field', $err->category);
        }
    }

    public function testAndSoIsOneOnAGenericStruct(): void
    {
        $registry = self::loaded(ApplicationObjectsTestUser::class);
        try {
            Dextrin::encodeBinary(Dextrin::decode('%User{name: "a"}', $registry), registry: $registry);
            self::fail('expected a refusal');
        } catch (DXNError $err) {
            self::assertSame('schema/missing-field', $err->category);
        }
    }

    public function testAnAbsentFieldWithADefaultIsWrittenAsItsDefault(): void
    {
        $registry = Schema::compile(
            Dextrin::decode('%{User: %schema{name: :string, role?: %field{type: :string, default: "guest"}}}'),
            new Registry(),
        );
        $bytes = Dextrin::encodeBinary(DXNStruct::keyed('User', [['name', 'a']], 1), registry: $registry);
        self::assertSame([['name', 'a'], ['role', 'guest']], Dextrin::decodeBinary($bytes, $registry)->pairs());
    }

    public function testAPropertyItsSchemaDoesNotDeclareIsAnExtraFieldInText(): void
    {
        $user = new ApplicationObjectsTestLoose('a');
        $user->nick = 'b';
        self::assertSame(
            '%User(2){name:"a",nick:"b"}',
            Dextrin::encode($user, registry: self::loaded(ApplicationObjectsTestLoose::class)),
        );
    }

    public function testAndInBinaryAsTheTrailingTag215Map(): void
    {
        $registry = self::loaded(ApplicationObjectsTestLoose::class);
        $loose = new ApplicationObjectsTestLoose('a');
        $loose->email = 'a@x';
        $loose->nick = 'b';
        $read = Dextrin::decodeBinary(Dextrin::encodeBinary($loose, registry: $registry), $registry);
        self::assertSame([['nick', 'b']], $read->extra);
    }
}
