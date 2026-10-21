<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\SchemaCompileError;
use JOetjen\Dextrin\Schema\SchemaValidationError;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNTuple;
use PHPUnit\Framework\TestCase;

/** An application's value, written by a registered tag encoder as `@acme.money {amount currency}`. */
final class TaggedMoney
{
    public function __construct(public readonly mixed $amount, public readonly string $currency)
    {
    }
}

/**
 * `{:tagged name T}`, the superset's group 7 (design/schema-superset.md,
 * Jan, 2026-10-20; as dextrin bdb20b3): a custom tag of that name whose
 * value is a `T` -- YAML's local tags, MessagePack's ext types, CBOR's
 * tags.
 */
final class TaggedTest extends TestCase
{
    private const SCHEMAS = '%{Price: {:tagged acme.money {:tuple-of :decimal :string}}}';

    private static function registry(): Registry
    {
        return Schema::compile(Dextrin::decode(self::SCHEMAS), new Registry());
    }

    /** Whether `$value` is a Price in `$registry`; only a type mismatch answers false. */
    private static function takes(mixed $value, ?Registry $registry = null): bool
    {
        try {
            return Schema::check($value, $registry ?? self::registry(), 'Price');
        } catch (SchemaValidationError $err) {
            self::assertSame('schema/type-mismatch', $err->category());
            return false;
        }
    }

    public function testACustomTagOfThatNameWhoseValueIsAT(): void
    {
        self::assertTrue(self::takes(Dextrin::decode('@acme.money {9.99M "EUR"}')));
        self::assertFalse(self::takes(Dextrin::decode('@acme.money {"9.99" "EUR"}')));
        self::assertFalse(self::takes(Dextrin::decode('@acme.cost {9.99M "EUR"}')));
        self::assertFalse(self::takes(Dextrin::decode('{9.99M "EUR"}')));
    }

    public function testTheNameMayBeWrittenAsASymbolAKeywordOrAString(): void
    {
        foreach (['acme.money', ':acme.money', '"acme.money"'] as $name) {
            $registry = Schema::compile(Dextrin::decode("%{Price: {:tagged {$name} :any}}"), new Registry());
            self::assertTrue(self::takes(Dextrin::decode('@acme.money 1'), $registry), $name);
        }
    }

    public function testAnApplicationsValueIsCheckedAsItsRegisteredTagEncoderWritesIt(): void
    {
        $registry = self::registry()->putTagEncoder(
            TaggedMoney::class,
            'acme.money',
            static fn (TaggedMoney $m): DXNTuple => new DXNTuple([$m->amount, $m->currency]),
        );

        self::assertTrue(self::takes(new TaggedMoney(DXNDecimal::fromString('9.99'), 'EUR'), $registry));
        self::assertFalse(self::takes(new TaggedMoney(9.99, 'EUR'), $registry));
        self::assertFalse(self::takes(new TaggedMoney(DXNDecimal::fromString('1'), 'EUR')));
    }

    public function testABuiltInTagsNameIsNoCustomTags(): void
    {
        try {
            Schema::compile(Dextrin::decode('%{T: {:tagged uuid :string}}'), new Registry());
            self::fail('a built-in tag should be refused');
        } catch (SchemaCompileError $err) {
            self::assertSame('schema/compile', $err->category());
            self::assertStringContainsString('uuid', $err->getMessage());
        }
    }

    public function testItGuardsANamedTypesRecursion(): void
    {
        $registry = Schema::compile(Dextrin::decode('%{Node: {:tagged acme.node {:list-of Node}}}'), new Registry());
        self::assertNotNull($registry->fetchTypeAlias('Node'));
    }
}
