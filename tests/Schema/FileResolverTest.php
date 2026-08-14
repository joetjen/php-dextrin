<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\FileResolver;
use PHPUnit\Framework\TestCase;

final class FileResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dextrin-file-resolver-test-' . bin2hex(random_bytes(8));
        mkdir($this->dir);
        file_put_contents(
            $this->dir . '/Geometry.dxns',
            <<<'DXN'
                %{
                  Point: %schema{ fields: @ordered %{ x: :float, y: :float } }
                  Circle: %schema{ fields: @ordered %{ radius: :float } }
                }
                DXN,
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testResolvesNamespaceSlashNameToTheNamedEntryInThatFile(): void
    {
        $resolver = FileResolver::forPaths([$this->dir]);
        $compiled = $resolver('Geometry/Circle');
        self::assertNotNull($compiled);
        self::assertSame(['radius'], $compiled->fieldNames());
    }

    public function testABareNameResolvesToTheFileOfTheSameName(): void
    {
        file_put_contents($this->dir . '/Standalone.dxns', '%{ Standalone: %schema{ fields: @ordered %{ n: :integer } } }');
        $resolver = FileResolver::forPaths([$this->dir]);
        $compiled = $resolver('Standalone');
        self::assertNotNull($compiled);
        self::assertSame(['n'], $compiled->fieldNames());
    }

    public function testReturnsNullWhenNoSearchPathHasTheFile(): void
    {
        $resolver = FileResolver::forPaths([$this->dir]);
        self::assertNull($resolver('Nowhere/AtAll'));
    }

    public function testReturnsNullWhenTheFileExistsButLacksTheEntry(): void
    {
        $resolver = FileResolver::forPaths([$this->dir]);
        self::assertNull($resolver('Geometry/NoSuchEntry'));
    }

    public function testFallsThroughToALaterSearchPathWhenAnEarlierOneHasTheFileButNotTheEntry(): void
    {
        $otherDir = $this->dir . '-other';
        mkdir($otherDir);
        try {
            // Both directories have their own Geometry.dxns; only the
            // second defines Point -- forPaths() must keep trying
            // remaining paths past a same-named-file-but-wrong-entry
            // miss, not stop at the first file it finds.
            file_put_contents($otherDir . '/Geometry.dxns', '%{ Square: %schema{ fields: @ordered %{ side: :float } } }');
            $resolver = FileResolver::forPaths([$otherDir, $this->dir]);
            self::assertNotNull($resolver('Geometry/Square'), 'defined in the first path');
            self::assertNotNull($resolver('Geometry/Point'), 'only defined in the second path');
        } finally {
            unlink($otherDir . '/Geometry.dxns');
            rmdir($otherDir);
        }
    }

    public function testPredicatesAreForwardedForRefineFnResolution(): void
    {
        file_put_contents(
            $this->dir . '/WithPredicate.dxns',
            '%{ Checked: %schema{ refine-fn: always-ok, fields: @ordered %{ n: :integer } } }',
        );
        $resolver = FileResolver::forPaths([$this->dir], ['always-ok' => static fn (array $unusedResolved): bool => true]);
        $compiled = $resolver('WithPredicate/Checked');
        self::assertNotNull($compiled);
        self::assertNotNull($compiled->refineFn);
    }

    public function testCompiledSchemasResolvedThisWayWorkWithRegistryPutResolver(): void
    {
        $registry = (new Registry())->putResolver(FileResolver::forPaths([$this->dir]));
        $found = $registry->fetchStructSchema('Geometry/Point');
        self::assertNotNull($found);
        self::assertSame(['x', 'y'], $found->compiled->fieldNames());
    }
}
