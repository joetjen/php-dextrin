<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\FileResolver;
use PHPUnit\Framework\TestCase;

/**
 * The file convention of DXN.md §4.6, as the reference follows it
 * (dextrin fd34537): versioned files in three layouts and the namespace
 * file, the first search path deciding, a file's version its schema's,
 * one version found once, every version loaded.
 */
final class FileResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dextrin-file-resolver-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    private function write(string $relative, string $source): void
    {
        $file = $this->dir . '/' . $relative;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $source);
    }

    private function resolved(string $text): mixed
    {
        return Dextrin::decode($text, (new Registry())->putResolver(FileResolver::forPaths([$this->dir])));
    }

    private function category(string $text): ?string
    {
        try {
            $this->resolved($text);
        } catch (DXNError $err) {
            return $err->category;
        }
        self::fail("expected {$text} to be refused");
    }

    public function testTheNamespaceFileResolvesUnderTheNameAskedFor(): void
    {
        $this->write('Shapes.dxns', '%{ Point: %schema{ x: :float, y: :float } }');
        $found = FileResolver::forPaths([$this->dir])('Shapes/Point');
        self::assertSame(['Shapes/Point', ['x', 'y']], [$found[0]->name, array_map(static fn ($f) => $f->name, $found[0]->fields)]);
    }

    public function testABareNameResolvesToItsOwnFile(): void
    {
        $this->write('Solo.dxns', '%{ Solo: %schema{ n: :integer } }');
        self::assertSame('Solo', FileResolver::forPaths([$this->dir])('Solo')[0]->name);
    }

    public function testNullForAFileThatIsNotThereOrAnEntryItLacks(): void
    {
        $this->write('Shapes.dxns', '%{ Point: %schema{ x: :float } }');
        self::assertNull(FileResolver::forPaths([$this->dir])('NoSuchFile/Entry'));
        self::assertNull(FileResolver::forPaths([$this->dir])('Shapes/NotDefined'));
    }

    public function testADottedNamespacedNameIsADirectoryOfVersionedFiles(): void
    {
        $this->write('Foo/Bar/Baz-v1.dxns', '%{Baz: %schema{a: :integer}}');
        $this->write('Foo/Bar/Baz-v2.dxns', '%{Baz: %schema(2){a: :integer, b?: :string}}');
        $value = $this->resolved('%Foo.Bar/Baz(2){a: 1}');
        self::assertSame(['Foo.Bar/Baz', 2], [$value->name, $value->version]);
    }

    public function testVersionOnesSuffixIsOptional(): void
    {
        $this->write('Foo/Bar/Baz.dxns', '%{Baz: %schema{a: :integer}}');
        self::assertSame(1, $this->resolved('%Foo.Bar/Baz{a: 1}')->version);
    }

    public function testADirectoryPerNameHoldsItsVersions(): void
    {
        $this->write('Foo/Bar/Baz/v1.dxns', '%{Baz: %schema{a: :integer}}');
        $this->write('Foo/Bar/Baz/v3.dxns', '%{Baz: %schema(3){a: :integer}}');
        self::assertSame(3, $this->resolved('%Foo.Bar/Baz{a: 1}')->version);
    }

    public function testAFlatDirectoryNamesThemWithDots(): void
    {
        $this->write('Foo.Bar.Baz-v2.dxns', '%{Baz: %schema(2){a: :integer}}');
        self::assertSame(2, $this->resolved('%Foo.Bar/Baz{a: 1}')->version);
    }

    public function testEveryVersionIsLoadedSoAnOlderStructReachesTheMaterializer(): void
    {
        $this->write('Foo/Bar/Baz-v1.dxns', '%{Baz: %schema{a: :integer}}');
        $this->write('Foo/Bar/Baz-v2.dxns', '%{Baz: %schema(2){a: :integer, b?: :string}}');
        self::assertSame('schema/no-materializer', $this->category('%Foo.Bar/Baz(1){a: 1}'));
    }

    public function testAFileWhoseSchemaIsNotTheVersionItsNameSaysIsRefused(): void
    {
        $this->write('Foo/Bar/Baz-v2.dxns', '%{Baz: %schema(3){a: :integer}}');
        self::assertSame('schema/compile', $this->category('%Foo.Bar/Baz{a: 1}'));
    }

    public function testOneVersionFoundInTwoFilesIsRefusedNamingBoth(): void
    {
        $this->write('Foo/Bar/Baz-v2.dxns', '%{Baz: %schema(2){a: :integer}}');
        $this->write('Foo/Bar/Baz/v2.dxns', '%{Baz: %schema(2){a: :integer}}');
        try {
            $this->resolved('%Foo.Bar/Baz{a: 1}');
        } catch (DXNError $err) {
            self::assertSame('schema/compile', $err->category);
            self::assertStringContainsString('Baz-v2.dxns', $err->getMessage());
            self::assertStringContainsString('Baz/v2.dxns', $err->getMessage());
            return;
        }
        self::fail('expected a refusal');
    }

    public function testAndSoIsAVersionedFileBesideTheNamespaceFileHoldingTheSameVersion(): void
    {
        $this->write('Foo.Bar.dxns', '%{Baz: %schema(2){a: :integer}}');
        $this->write('Foo/Bar/Baz-v2.dxns', '%{Baz: %schema(2){a: :integer}}');
        self::assertSame('schema/compile', $this->category('%Foo.Bar/Baz{a: 1}'));
    }

    public function testTheNamespaceFileKeepsWorkingWithDottedNamespaces(): void
    {
        $this->write('Foo.Bar.dxns', '%{Baz: %schema(2){a: :integer}}');
        self::assertSame(2, $this->resolved('%Foo.Bar/Baz{a: 1}')->version);
    }
}
