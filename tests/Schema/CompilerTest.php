<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\Compiler;
use JOetjen\Dextrin\Schema\Field;
use JOetjen\Dextrin\Schema\SchemaCompileError;
use PHPUnit\Framework\TestCase;

final class CompilerTest extends TestCase
{
    private const DOC = <<<'DXN'
        %{
          Point: %schema{
            fields: @ordered %{
              x: :float
              y: :float
              label?: %field{type: :string, default: "unlabeled", description: "a caption"}
            }
          }
          PositiveInt: {:refine :integer %{min: 1}}
          Coord: Point
        }
        DXN;

    public function testCompilesASchemaEntryWithOrderedFieldsAndAField(): void
    {
        $registry = Compiler::compile(Dextrin::decode(self::DOC), new Registry());
        $found = $registry->fetchStructSchema('Point');
        self::assertNotNull($found);
        $compiled = $found->compiled;

        self::assertSame('Point', $compiled->name);
        self::assertSame(['x', 'y', 'label'], $compiled->fieldNames());
        self::assertFalse($compiled->closed);
        self::assertSame([], $compiled->forbidden);

        [$x, $y, $label] = $compiled->fields;
        self::assertTrue($x->required);
        self::assertTrue($y->required);
        self::assertFalse($label->required, '"label?" is optional');
        self::assertSame('unlabeled', $label->default);
        self::assertNotSame(Field::NO_DEFAULT, $label->default);
        self::assertSame('a caption', $label->description);
        self::assertSame(Field::NO_DEFAULT, $x->default, 'a field with no %field{} default has none');
    }

    public function testCompilesANamedRefineTypeUsableAsAField(): void
    {
        $registry = Compiler::compile(Dextrin::decode(self::DOC), new Registry());
        $type = $registry->fetchTypeAlias('PositiveInt');
        self::assertNotNull($type);
        self::assertTrue($type->matches(1));
        self::assertFalse($type->matches(0));
    }

    public function testANamedTypeCanBeABareReferenceToAStructName(): void
    {
        $registry = Compiler::compile(Dextrin::decode(self::DOC), new Registry());
        $coord = $registry->fetchTypeAlias('Coord');
        self::assertNotNull($coord);
        self::assertSame('reference', $coord->form);
        self::assertSame('Point', $coord->name);
    }

    public function testClosedAndForbiddenAreHonored(): void
    {
        $doc = <<<'DXN'
            %{
              Strict: %schema{
                closed: true
                forbidden: [password]
                fields: @ordered %{ name: :string }
              }
            }
            DXN;
        $compiled = Compiler::compile(Dextrin::decode($doc), new Registry())->fetchStructSchema('Strict')?->compiled;
        self::assertNotNull($compiled);
        self::assertTrue($compiled->closed);
        self::assertSame(['password'], $compiled->forbidden);
    }

    public function testRefineFnIsResolvedFromThePredicatesMap(): void
    {
        $doc = <<<'DXN'
            %{
              Range: %schema{
                refine-fn: valid-range
                fields: @ordered %{ low: :integer, high: :integer }
              }
            }
            DXN;
        $predicate = static fn (array $resolved): bool|string => $resolved['low'] <= $resolved['high'] ? true : 'low must not exceed high';
        $compiled = Compiler::compile(Dextrin::decode($doc), new Registry(), ['valid-range' => $predicate])
            ->fetchStructSchema('Range')?->compiled;
        self::assertNotNull($compiled);
        self::assertNotNull($compiled->refineFn);
        self::assertTrue(($compiled->refineFn)(['low' => 1, 'high' => 2]));
        self::assertSame('low must not exceed high', ($compiled->refineFn)(['low' => 2, 'high' => 1]));
    }

    public function testUnresolvedRefineFnThrows(): void
    {
        $doc = <<<'DXN'
            %{
              Range: %schema{
                refine-fn: missing-predicate
                fields: @ordered %{ low: :integer }
              }
            }
            DXN;
        $this->expectException(SchemaCompileError::class);
        Compiler::compile(Dextrin::decode($doc), new Registry());
    }

    public function testNonMapDocumentThrows(): void
    {
        $this->expectException(SchemaCompileError::class);
        Compiler::compile('not-a-map', new Registry());
    }

    public function testFieldsMustBeAnOrderedMap(): void
    {
        $doc = <<<'DXN'
            %{
              Bad: %schema{
                fields: %{ x: :integer }
              }
            }
            DXN;
        $this->expectException(SchemaCompileError::class);
        Compiler::compile(Dextrin::decode($doc), new Registry());
    }

    public function testUnrecognizedPrimitiveNameThrows(): void
    {
        $doc = <<<'DXN'
            %{
              Bad: %schema{
                fields: @ordered %{ x: :not-a-real-primitive }
              }
            }
            DXN;
        $this->expectException(SchemaCompileError::class);
        Compiler::compile(Dextrin::decode($doc), new Registry());
    }

    public function testUnrecognizedTypeExpressionThrows(): void
    {
        $doc = <<<'DXN'
            %{
              Bad: %schema{
                fields: @ordered %{ x: {1, 2, 3} }
              }
            }
            DXN;
        $this->expectException(SchemaCompileError::class);
        $this->expectExceptionMessageMatches('/unrecognized type expression/');
        Compiler::compile(Dextrin::decode($doc), new Registry());
    }

    public function testCompoundTypeExpressionForms(): void
    {
        $doc = <<<'DXN'
            %{
              Shape: %schema{
                fields: @ordered %{
                  tags: {:list-of :string}
                  ids: {:set-of :integer}
                  point: {:tuple-of :float :float}
                  meta: {:map-of :string :integer}
                  color: {:enum :red :green :blue}
                  code: {:one-of :nil :integer}
                  positive: {:all-of :integer {:refine :integer %{min: 1}}}
                  nickname: {:nilable :string}
                }
              }
            }
            DXN;
        $compiled = Compiler::compile(Dextrin::decode($doc), new Registry())->fetchStructSchema('Shape')?->compiled;
        self::assertNotNull($compiled);
        $forms = array_map(static fn (Field $f): string => $f->type->form, $compiled->fields);
        self::assertSame(
            ['list-of', 'set-of', 'tuple-of', 'map-of', 'enum', 'one-of', 'all-of', 'nilable'],
            $forms,
        );
    }
}
