<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

final class StructReferenceTestUser
{
    public function __construct(public ?string $name = null)
    {
    }
}

final class StructReferenceTestOther
{
    public function __construct(public ?string $name = null)
    {
    }
}

/**
 * A value matches only its declared type, and nothing is converted to
 * make it match (DXNS.md §7). A field typed `User` accepts only a `User`
 * struct; a map is not a struct.
 */
final class StructReferenceTest extends TestCase
{
    private const SCHEMAS = '%{User: %schema{name: :string}, Order: %schema{id: :integer, user: User}}';

    private static function loaded(): Registry
    {
        return Schema::compile(Dextrin::decode(self::SCHEMAS), new Registry());
    }

    public function testAStructOfTheReferencedNameIsAccepted(): void
    {
        self::assertInstanceOf(DXNStruct::class, Dextrin::decode('%Order{id: 1, user: %User{name: "a"}}', self::loaded()));
    }

    public function testAMapIsRefusedHoweverMuchItLooksLikeTheStruct(): void
    {
        $this->expectException(DXNError::class);
        Dextrin::decode('%Order{id: 1, user: %{name: "a"}}', self::loaded());
    }

    public function testAndSoIsAnyOtherValue(): void
    {
        $this->expectException(DXNError::class);
        Dextrin::decode('%Order{id: 1, user: "a"}', self::loaded());
    }

    public function testTheRegisteredApplicationClassIsAcceptedOnTheWayOut(): void
    {
        $registry = self::loaded()->putStructModule('User', StructReferenceTestUser::class);
        $order = DXNStruct::keyed('Order', [['id', 1], ['user', new StructReferenceTestUser('a')]]);
        self::assertStringStartsWith('%Order', Dextrin::encode($order, registry: $registry));
    }

    public function testAMapIsRefusedOnTheWayOut(): void
    {
        $order = DXNStruct::keyed('Order', [['id', 1], ['user', ['name' => 'a']]]);
        $this->expectException(DXNError::class);
        Dextrin::encode($order, registry: self::loaded());
    }

    public function testAndSoIsAClassRegisteredForNoSchemaOrForAnother(): void
    {
        $registry = self::loaded()->putStructModule('User', StructReferenceTestUser::class);
        $order = DXNStruct::keyed('Order', [['id', 1], ['user', new StructReferenceTestOther('a')]]);
        $this->expectException(DXNError::class);
        Dextrin::encode($order, registry: $registry);
    }

    public function testAFloatIsNeverTurnedIntoARationalBecauseAFieldSaysSo(): void
    {
        $registry = Schema::compile(Dextrin::decode('%{P: %schema{amount: :rational}}'), new Registry());
        $this->expectException(DXNError::class);
        Dextrin::encode(DXNStruct::keyed('P', [['amount', 0.5]]), registry: $registry);
    }
}
