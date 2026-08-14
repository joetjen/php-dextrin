<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\Compiler;
use JOetjen\Dextrin\Schema\Validated;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private const POINT_SCHEMA = <<<'DXN'
        %{
          Point: %schema{
            fields: @ordered %{
              x: :float
              y: :float
              label?: %field{type: :string, default: "unlabeled"}
            }
          }
        }
        DXN;

    private function pointRegistry(): Registry
    {
        return Compiler::compile(Dextrin::decode(self::POINT_SCHEMA), new Registry());
    }

    // ---- decode-side (materialize) --------------------------------------------

    public function testDecodeMaterializesAKeyedStructFillingInTheDefault(): void
    {
        $value = Dextrin::decode('%Point{x: 1.5, y: 2.5}', $this->pointRegistry());
        self::assertSame(['x' => 1.5, 'y' => 2.5, 'label' => 'unlabeled'], $value);
    }

    public function testDecodeMaterializesAPositionalStructInFieldOrder(): void
    {
        $value = Dextrin::decode('%Point[1.5, 2.5, "origin"]', $this->pointRegistry());
        self::assertSame(['x' => 1.5, 'y' => 2.5, 'label' => 'origin'], $value);
    }

    public function testDecodedTopLevelStructIsNotLeftWrappedInValidated(): void
    {
        // parse()/decode() must strip Validated at the true top of
        // decoding -- regression coverage for exactly that.
        $value = Dextrin::decode('%Point{x: 1.0, y: 2.0}', $this->pointRegistry());
        self::assertNotInstanceOf(Validated::class, $value);
        self::assertIsArray($value);
    }

    public function testBinaryDecodeAlsoMaterializesAndStrips(): void
    {
        $registry = $this->pointRegistry();
        $binary = Dextrin::encodeBinary(DXNStruct::positional('Point', [1.5, 2.5, 'here']), validate: false);
        $value = Dextrin::decodeBinary($binary, $registry);
        self::assertSame(['x' => 1.5, 'y' => 2.5, 'label' => 'here'], $value);
    }

    public function testUnregisteredStructNameStillDecodesToAnOpaqueDXNStruct(): void
    {
        $value = Dextrin::decode('%Other{a: 1}', $this->pointRegistry());
        self::assertInstanceOf(DXNStruct::class, $value);
    }

    public function testMissingRequiredFieldThrowsAsAParseError(): void
    {
        $this->expectException(DXNError::class);
        Dextrin::decode('%Point{x: 1.0}', $this->pointRegistry());
    }

    public function testFieldTypeMismatchThrows(): void
    {
        $this->expectException(DXNError::class);
        Dextrin::decode('%Point{x: "nope", y: 2.0}', $this->pointRegistry());
    }

    public function testWrongPositionalFieldCountThrows(): void
    {
        $this->expectException(DXNError::class);
        Dextrin::decode('%Point[1.0]', $this->pointRegistry());
    }

    public function testClosedSchemaRejectsUnknownFields(): void
    {
        $doc = <<<'DXN'
            %{
              Strict: %schema{
                closed: true
                fields: @ordered %{ name: :string }
              }
            }
            DXN;
        $registry = Compiler::compile(Dextrin::decode($doc), new Registry());
        $this->expectException(DXNError::class);
        Dextrin::decode('%Strict{name: "a", extra: 1}', $registry);
    }

    public function testForbiddenFieldIsRejectedEvenWhenNotClosed(): void
    {
        $doc = <<<'DXN'
            %{
              Strict: %schema{
                forbidden: [password]
                fields: @ordered %{ name: :string }
              }
            }
            DXN;
        $registry = Compiler::compile(Dextrin::decode($doc), new Registry());
        $this->expectException(DXNError::class);
        Dextrin::decode('%Strict{name: "a", password: "hunter2"}', $registry);
    }

    public function testRefineFnRejectionSurfacesItsOwnMessage(): void
    {
        $doc = <<<'DXN'
            %{
              Range: %schema{
                refine-fn: valid-range
                fields: @ordered %{ low: :integer, high: :integer }
              }
            }
            DXN;
        $predicate = static fn (array $r): bool|string => $r['low'] <= $r['high'] ? true : 'low must not exceed high';
        $registry = Compiler::compile(Dextrin::decode($doc), new Registry(), ['valid-range' => $predicate]);
        try {
            Dextrin::decode('%Range{low: 5, high: 1}', $registry);
            self::fail('expected a schema violation');
        } catch (DXNError $e) {
            self::assertStringContainsString('low must not exceed high', $e->getMessage());
        }
    }

    public function testMaterializerProducesTheCustomShapeInsteadOfThePlainFieldMap(): void
    {
        $registry = $this->pointRegistry()->putStructMaterializer(
            'Point',
            static fn (array $r): string => "({$r['x']}, {$r['y']})",
        );
        $value = Dextrin::decode('%Point{x: 1.0, y: 2.0}', $registry);
        self::assertSame('(1, 2)', $value);
    }

    // ---- encode-side (validateEncode / validateEncodeTree / coercion) --------

    public function testEncodeWithSchemaPromotesABareArrayToTheNamedStruct(): void
    {
        $text = Dextrin::encode(['x' => 1.0, 'y' => 2.0], registry: $this->pointRegistry(), schema: 'Point');
        self::assertSame('%Point{x:1.0,y:2.0,label:"unlabeled"}', $text);
    }

    public function testEncodeTreeAutomaticallyValidatesARegisteredDXNStruct(): void
    {
        // rebuildChecked() keeps an *already-keyed* DXNStruct's own
        // field set as given (types checked/coerced in place) --
        // unlike a bare map or positional struct, it doesn't inject
        // defaulted-in fields the caller never mentioned.
        $registry = $this->pointRegistry();
        $text = Dextrin::encode(DXNStruct::keyed('Point', [['x', 1.0], ['y', 2.0]]), registry: $registry);
        self::assertSame('%Point{x:1.0,y:2.0}', $text);
    }

    public function testEncodeTreeValidatesNestedStructsRegardlessOfEnclosingType(): void
    {
        $registry = $this->pointRegistry();
        $value = ['origin' => DXNStruct::keyed('Point', [['x', 0.0], ['y', 0.0]])];
        $text = Dextrin::encode($value, registry: $registry);
        self::assertSame('%{origin:%Point{x:0.0,y:0.0}}', $text);
    }

    public function testEncodeCanBeToldNotToValidate(): void
    {
        // Missing a required field would normally throw -- validate:
        // false skips the automatic tree walk entirely.
        $text = Dextrin::encode(
            DXNStruct::keyed('Point', [['x', 1.0]]),
            registry: $this->pointRegistry(),
            validate: false,
        );
        self::assertSame('%Point{x:1.0}', $text);
    }

    public function testEncodeSchemaViolationThrowsDXNErrorWithEncodeStage(): void
    {
        try {
            Dextrin::encode(['x' => 'nope'], registry: $this->pointRegistry(), schema: 'Point');
            self::fail('expected a schema violation');
        } catch (DXNError $e) {
            self::assertSame('encode', $e->stage);
        }
    }

    public function testCoercionOnEncodeConvertsAFloatToARationalField(): void
    {
        $doc = <<<'DXN'
            %{
              Fraction: %schema{ fields: @ordered %{ value: :rational } }
            }
            DXN;
        $registry = Compiler::compile(Dextrin::decode($doc), new Registry());
        $text = Dextrin::encode(['value' => 0.5], registry: $registry, schema: 'Fraction');
        self::assertSame('%Fraction{value:1/2}', $text);
    }

    public function testCoerceFalseRejectsWhatWouldOtherwiseBeCoerced(): void
    {
        $doc = <<<'DXN'
            %{
              Fraction: %schema{ fields: @ordered %{ value: :rational } }
            }
            DXN;
        $registry = Compiler::compile(Dextrin::decode($doc), new Registry());
        $this->expectException(DXNError::class);
        Dextrin::encode(['value' => 0.5], registry: $registry, schema: 'Fraction', coerce: false);
    }

    public function testSchemaFacadeValidateChecksAnAlreadyDecodedStruct(): void
    {
        $registry = $this->pointRegistry();
        $struct = DXNStruct::keyed('Point', [['x', 1.0], ['y', 2.0]]);
        self::assertTrue(Schema::validate($struct, $registry, 'Point'));
    }

    public function testSchemaFacadeValidateEncodeReturnsTheCoercedValue(): void
    {
        $doc = <<<'DXN'
            %{
              Fraction: %schema{ fields: @ordered %{ value: :rational } }
            }
            DXN;
        $registry = Compiler::compile(Dextrin::decode($doc), new Registry());
        $result = Schema::validateEncode(['value' => 0.5], $registry, 'Fraction');
        self::assertInstanceOf(DXNStruct::class, $result);
        self::assertEquals(new DXNRational('1', '2'), $result->pairs()[0][1]);
    }

    /**
     * PHP-specific limitation, not shared by the JS port (whose
     * objects are always mutable): rebuilding a registered application
     * instance during encode-time validation writes field values back
     * via direct property assignment on a clone, which throws for a
     * `readonly` property -- there is no generic "reconstruct with
     * these fields changed" for those in PHP the way there is for a
     * `DXNStruct`.
     */
    public function testEncodingARegisteredReadonlyPropertyInstanceThrows(): void
    {
        $doc = <<<'DXN'
            %{
              ReadonlyPoint: %schema{ fields: @ordered %{ x: :float, y: :float } }
            }
            DXN;
        $registry = Compiler::compile(Dextrin::decode($doc), new Registry())
            ->putStructModule('ReadonlyPoint', ValidatorTestReadonlyPoint::class);

        $this->expectException(DXNError::class);
        Dextrin::encode(new ValidatorTestReadonlyPoint(1.0, 2.0), registry: $registry);
    }
}

final class ValidatorTestReadonlyPoint
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
    ) {
    }
}
