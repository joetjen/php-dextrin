<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\SchemaCompileError;
use PHPUnit\Framework\TestCase;

/**
 * Schema::compileAll(): several .dxns documents into one registry, in
 * order, as the reference's Dextrin.Schema.compile_all/2 (dextrin
 * 8fb723a).
 */
final class CompileAllTest extends TestCase
{
    public function testCompilesEveryDocumentIntoOneRegistryInOrder(): void
    {
        $registry = Schema::compileAll([
            Dextrin::decode('%{Address: %schema{city: :string}}'),
            Dextrin::decode('%{Person: %schema{home: Address}}'),
        ]);
        self::assertNotNull($registry->fetchStructSchema('Address'));
        self::assertNotNull($registry->fetchStructSchema('Person'));
    }

    public function testOverABaseRegistry(): void
    {
        $base = Schema::compile(Dextrin::decode('%{A: %schema{a: :integer}}'), new Registry());
        $registry = Schema::compileAll([Dextrin::decode('%{B: %schema{b: :integer}}')], $base);
        self::assertNotNull($registry->fetchStructSchema('A'));
    }

    public function testRefusesTheFirstOneThatDoesNotCompileSayingWhich(): void
    {
        try {
            Schema::compileAll([Dextrin::decode('%{A: %schema{a: :integer}}'), Dextrin::decode('[1]')]);
        } catch (SchemaCompileError $err) {
            self::assertSame('schema/compile', $err->category());
            self::assertStringContainsString('document 2', $err->getMessage());
            return;
        }
        self::fail('expected a refusal');
    }
}
