<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\Compiled;
use JOetjen\Dextrin\Schema\TypeExpr;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    public function testPutMethodsReturnNewInstancesLeavingTheOriginalUnchanged(): void
    {
        $original = new Registry();
        $withTag = $original->putTag('my-tag', static fn (mixed $v): mixed => $v);

        self::assertNull($original->fetchTag('my-tag'));
        self::assertNotNull($withTag->fetchTag('my-tag'));
    }

    public function testFetchTagRoundTrips(): void
    {
        $decoder = static fn (mixed $v): string => "decoded:{$v}";
        $registry = (new Registry())->putTag('greeting', $decoder);
        $found = $registry->fetchTag('greeting');
        self::assertNotNull($found);
        self::assertSame('decoded:hi', $found('hi'));
        self::assertNull($registry->fetchTag('unknown'));
    }

    public function testFetchTagEncoderRoundTrips(): void
    {
        $encoder = static fn (object $unusedValue): string => 'encoded';
        $registry = (new Registry())->putTagEncoder(\stdClass::class, 'std', $encoder);
        $found = $registry->fetchTagEncoder(\stdClass::class);
        self::assertSame(['std', $encoder], $found);
        self::assertNull($registry->fetchTagEncoder(self::class));
    }

    public function testPutAndFetchStructSchema(): void
    {
        $compiled = new Compiled(fields: []);
        $registry = (new Registry())->putStructSchema('Point', $compiled);
        $found = $registry->fetchStructSchema('Point');
        self::assertNotNull($found);
        self::assertSame($compiled, $found->compiled);
        self::assertNull($registry->fetchStructSchema('Missing'));
    }

    public function testResolverIsConsultedOnMissAndCachedAfterward(): void
    {
        $compiled = new Compiled(fields: []);
        $calls = 0;
        $registry = (new Registry())->putResolver(static function (string $name) use ($compiled, &$calls): ?Compiled {
            $calls++;
            return $name === 'Lazy' ? $compiled : null;
        });

        $found = $registry->fetchStructSchema('Lazy');
        self::assertNotNull($found);
        self::assertSame($compiled, $found->compiled);
        self::assertSame(1, $calls);

        // The resolved schema is cached on the *returned* registry, not
        // the original -- the resolver runs again against the original.
        self::assertNotNull($registry->fetchStructSchema('Lazy'));
        self::assertSame(2, $calls);

        self::assertNotNull($found->registry->fetchStructSchema('Lazy'));
        self::assertSame(2, $calls, 'the registry returned alongside a resolver hit should cache it');

        self::assertNull($registry->fetchStructSchema('NeverResolved'));
    }

    public function testPutAndFetchMaterializer(): void
    {
        $materializer = static fn (string $name, int $version, array $fields): array => $fields;
        $registry = (new Registry())->putStructMaterializer('Point', $materializer);
        self::assertSame($materializer, $registry->fetchMaterializer('Point'));
        self::assertNull($registry->fetchMaterializer('Missing'));
    }

    public function testPutAndFetchTypeAlias(): void
    {
        $type = TypeExpr::primitive('integer');
        $registry = (new Registry())->putTypeAlias('MyInt', $type);
        self::assertSame($type, $registry->fetchTypeAlias('MyInt'));
        self::assertSame(['MyInt' => $type], $registry->typeAliases());
        self::assertNull($registry->fetchTypeAlias('Missing'));
    }

    public function testPutAndFetchStructModuleBothDirections(): void
    {
        $registry = (new Registry())->putStructModule('Point', \stdClass::class);
        self::assertSame(\stdClass::class, $registry->fetchStructModule('Point'));
        self::assertSame('Point', $registry->fetchSchemaNameForModule(\stdClass::class));
        self::assertNull($registry->fetchStructModule('Missing'));
        self::assertNull($registry->fetchSchemaNameForModule(self::class));
    }
}
