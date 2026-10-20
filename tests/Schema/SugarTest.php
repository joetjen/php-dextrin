<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\SchemaCompileError;
use JOetjen\Dextrin\Schema\SchemaValidationError;
use PHPUnit\Framework\TestCase;

/**
 * Sugar, the superset's group 9 (design/schema-superset.md, Jan,
 * 2026-10-20; as dextrin d1b050a): protobuf's and MongoDB's sized
 * numbers, `:number`, `{:const v}` and `{:if I T E}`. Each says nothing
 * a longer form does not; DXN.md §4.1 gives the form each stands for.
 */
final class SugarTest extends TestCase
{
    private static function compiled(string $type): Registry
    {
        return Schema::compile(Dextrin::decode("%{T: {$type}}"), new Registry());
    }

    /** Whether the value written `$text` is a `$type`; only a type mismatch answers false. */
    private static function takes(string $type, string $text): bool
    {
        $registry = self::compiled($type);
        try {
            return Schema::check(Dextrin::decode($text), $registry, 'T');
        } catch (SchemaValidationError $err) {
            self::assertSame('schema/type-mismatch', $err->category());
            return false;
        }
    }

    public function testTheSizedIntegersAreTheirRanges(): void
    {
        self::assertTrue(self::takes(':int32', '-2147483648'));
        self::assertFalse(self::takes(':int32', '2147483648'));
        self::assertTrue(self::takes(':int64', '9223372036854775807'));
        self::assertFalse(self::takes(':int64', '-9223372036854775809'));
        self::assertTrue(self::takes(':uint32', '4294967295'));
        self::assertFalse(self::takes(':uint32', '-1'));
        self::assertTrue(self::takes(':uint64', '18446744073709551615'));
        self::assertFalse(self::takes(':uint64', '18446744073709551616'));
        self::assertFalse(self::takes(':int32', '1.0'));
    }

    public function testFloat32IsAFloatSinglePrecisionHolds(): void
    {
        self::assertTrue(self::takes(':float32', '1.5'));
        self::assertFalse(self::takes(':float32', '0.1'));
        self::assertFalse(self::takes(':float32', '1'));
    }

    public function testNumberIsAnyNumber(): void
    {
        foreach (['1', '1.5', '1.5M', '1/3', 'NaN'] as $text) {
            self::assertTrue(self::takes(':number', $text), $text);
        }
        self::assertFalse(self::takes(':number', '"1"'));
    }

    public function testConstVIsTheOneValueV(): void
    {
        self::assertTrue(self::takes('{:const "v1"}', '"v1"'));
        self::assertFalse(self::takes('{:const "v1"}', '"v2"'));
        self::assertTrue(self::takes('{:const %{a: 1}}', '%{a: 1}'));
    }

    public function testIfITEAValueThatIsAnIIsCheckedAsATAnyOtherAsAnE(): void
    {
        $type = '{:if :integer {:refine :integer %{min: 0}} :string}';
        self::assertTrue(self::takes($type, '5'));
        self::assertFalse(self::takes($type, '-5'));
        self::assertTrue(self::takes($type, '"x"'));
        self::assertFalse(self::takes($type, '1.5'));
    }

    public function testIfITLeavesAValueThatIsNoIAlone(): void
    {
        $type = '{:if :integer {:refine :integer %{min: 0}}}';
        self::assertTrue(self::takes($type, '1.5'));
        self::assertFalse(self::takes($type, '-5'));
    }

    public function testIfIsNoGuardItChecksTheSameValue(): void
    {
        $this->expectException(SchemaCompileError::class);
        Schema::compile(Dextrin::decode('%{A: {:if :integer A :string}}'), new Registry());
    }
}
