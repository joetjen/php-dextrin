<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\DXNSchemaMaterializingProvider;
use JOetjen\Dextrin\Schema\DXNSchemaProvider;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

/**
 * A minimal, mutable point class -- deliberately not `readonly`, since
 * {@see Validator::rebuildChecked()} writes coerced field values back
 * onto a clone via direct property assignment (see
 * {@see \JOetjen\Dextrin\Tests\Schema\ValidatorTest} for that
 * PHP-specific readonly-property limitation covered on its own).
 */
final class ProviderTestPoint
{
    public function __construct(
        public float $x,
        public float $y,
    ) {
    }
}

final class ProviderTestPointProvider implements DXNSchemaMaterializingProvider
{
    public function dxnSchema(): string
    {
        return '%{ Point: %schema{ x: :float, y: :float } }';
    }

    public function dxnSchemaName(): string
    {
        return 'Point';
    }

    public function dxnClass(): string
    {
        return ProviderTestPoint::class;
    }

    public function dxnMaterialize(string $name, int $version, array $fields): ProviderTestPoint
    {
        return new ProviderTestPoint($fields['x'], $fields['y']);
    }
}

final class ProviderTestNonMaterializingProvider implements DXNSchemaProvider
{
    public function dxnSchema(): string
    {
        return '%{ Point: %schema{ x: :float, y: :float } }';
    }

    public function dxnSchemaName(): string
    {
        return 'Point';
    }

    public function dxnClass(): string
    {
        return \stdClass::class;
    }
}

final class ProviderTestMismatchedNameProvider implements DXNSchemaProvider
{
    public function dxnSchema(): string
    {
        return '%{ Point: %schema{ x: :float } }';
    }

    public function dxnSchemaName(): string
    {
        return 'NotInTheDocument';
    }

    public function dxnClass(): string
    {
        return \stdClass::class;
    }
}

final class ProviderTest extends TestCase
{
    public function testRegisterProviderCompilesTheSchemaAndAssociatesTheClass(): void
    {
        $registry = Schema::registerProvider(new Registry(), new ProviderTestPointProvider());
        self::assertSame(ProviderTestPoint::class, $registry->fetchStructModule('Point'));
        self::assertSame('Point', $registry->fetchSchemaNameForModule(ProviderTestPoint::class));
        self::assertNotNull($registry->fetchStructSchema('Point'));
    }

    public function testDecodingThroughARegisteredProviderUsesItsMaterializer(): void
    {
        $registry = Schema::registerProvider(new Registry(), new ProviderTestPointProvider());
        $value = Dextrin::decode('%Point{x: 1.0, y: 2.0}', $registry);
        self::assertInstanceOf(ProviderTestPoint::class, $value);
        self::assertSame(1.0, $value->x);
        self::assertSame(2.0, $value->y);
    }

    public function testAProviderWithNoMaterializerFallsBackToTheGenericStruct(): void
    {
        $registry = Schema::registerProvider(new Registry(), new ProviderTestNonMaterializingProvider());
        self::assertNull($registry->fetchMaterializer('Point'));
        $value = Dextrin::decode('%Point{x: 1.0, y: 2.0}', $registry);
        // Marked string keys, not keywords -- see MapBuilder::markStringKey().
        self::assertTrue(DXNStruct::keyed('Point', [['x', 1.0], ['y', 2.0]], 1)->equals($value));
    }

    public function testEncodingARegisteredApplicationInstanceValidatesItAgainstItsSchema(): void
    {
        // Printer/Encoder check the registry for a schema name
        // registered against $point's own class before falling back
        // to the class's own short name -- so this prints as
        // `%Point{...}` (the schema name), not `%ProviderTestPoint{...}`
        // (the PHP class name), even though rebuildChecked() rebuilds
        // the value as a real ProviderTestPoint instance, not a
        // DXNStruct.
        $registry = Schema::registerProvider(new Registry(), new ProviderTestPointProvider());
        $point = new ProviderTestPoint(1.0, 2.0);
        $text = Dextrin::encode($point, registry: $registry);
        self::assertSame('%Point(1){x:1.0,y:2.0}', $text);
    }

    public function testAMismatchedSchemaNameThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/NotInTheDocument/');
        Schema::registerProvider(new Registry(), new ProviderTestMismatchedNameProvider());
    }
}
