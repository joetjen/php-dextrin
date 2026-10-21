<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Schema\Docs;
use PHPUnit\Framework\TestCase;

/**
 * `Docs` and `bin/dextrin docs`, as dextrin's `Dextrin.Schema.Docs` (Jan,
 * 2026-10-20). The example is the reference's, and so is its generated
 * `doc/`: every implementation must write it byte for byte.
 */
final class DocsTest extends TestCase
{
    /** @return array<string, string> every file under `$dir`, by its path below it */
    private static function filesUnder(string $dir): array
    {
        $found = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            /** @var \SplFileInfo $file */
            $found[substr($file->getPathname(), strlen($dir) + 1)] = (string) file_get_contents($file->getPathname());
        }
        ksort($found);
        return $found;
    }

    /**
     * @param array<string, string> $files
     * @return array<string, string>
     */
    private static function site(array $files): array
    {
        $root = sys_get_temp_dir() . '/dextrin-docs-' . uniqid('', true);
        foreach ($files as $path => $source) {
            @mkdir(dirname("{$root}/{$path}"), 0777, true);
            file_put_contents("{$root}/{$path}", $source);
        }
        $pages = [];
        foreach (Docs::site($root, 'T') as [$path, $content]) {
            $pages[$path] = $content;
        }
        return $pages;
    }

    public function testItWritesTheExampleExactlyAsTheReferenceDoes(): void
    {
        $expected = self::filesUnder(__DIR__ . '/../../example/doc');
        $made = [];
        foreach (Docs::site(__DIR__ . '/../../example/schemas', 'Acme schemas') as [$path, $content]) {
            $made[$path] = $content;
        }
        ksort($made);
        self::assertSame(array_keys($expected), array_keys($made));
        foreach ($expected as $path => $content) {
            self::assertSame($content, $made[$path], $path);
        }
    }

    public function testEveryDirectoryOfTheSiteHasItsOwnIndexHtml(): void
    {
        $pages = self::site(['a.b.c.dxns' => '%{T: :string}', 'x/y/Z-v1.dxns' => '%{Z: %schema{v: :integer}}']);
        foreach (array_keys($pages) as $path) {
            $dir = dirname($path);
            self::assertArrayHasKey($dir === '.' ? 'index.html' : "{$dir}/index.html", $pages, $dir);
        }
    }

    public function testADocumentThatIsNotAMapIsRefusedNamingItsFile(): void
    {
        $root = sys_get_temp_dir() . '/dextrin-docs-bad-' . uniqid('', true);
        mkdir($root);
        file_put_contents("{$root}/bad.dxns", '[1 2]');
        $this->expectExceptionMessageMatches('/bad\.dxns/');
        Docs::site($root);
    }

    // ---- annotations, {:doc T %{...}} (DXNS.md §1b; as dextrin 053432a) ----------

    private const ANNOTATED = <<<'DXN'
        %{
          Order: {:doc %schema(2){
            id: :integer
            email: {:doc :string %{description: "Where *receipts* go", read-only: true}}
            legacy?: {:doc :string %{deprecated: "use email"}}
          } %{title: "An order", description: "One checkout.", examples: [%Order(2){id: 1, email: "a@b"}]}}

          Port: {:doc {:refine :integer %{min: 1}} %{deprecated: true, meta: %{"acme.owner" => "net"}}}
        }
        DXN;

    /**
     * The page among `$pages` that defines `$id`.
     *
     * @param array<string, string> $pages
     */
    private static function pageWith(array $pages, string $id): string
    {
        foreach ($pages as $page) {
            if (str_contains($page, "id=\"{$id}\"")) {
                return $page;
            }
        }
        self::fail("no page defines {$id}");
    }

    public function testAnAnnotatedSchemaIsStillASchemaItsFieldsStillFields(): void
    {
        $html = self::pageWith(self::site(['shop.dxns' => self::ANNOTATED]), 'Order');
        self::assertStringContainsString('struct schema', $html);
        self::assertStringContainsString('<code>email</code>', $html);
        self::assertStringNotContainsString('{:doc', $html);
    }

    public function testATitleADescriptionExamplesAndMetaAreShownWithTheDefinition(): void
    {
        $html = self::pageWith(self::site(['shop.dxns' => self::ANNOTATED]), 'Order');
        foreach (['An order', 'One checkout.', 'Examples', 'a@b', 'acme.owner'] as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function testDeprecatedReadOnlyAndWriteOnlyAreChipsAFieldsDescriptionIsItsNote(): void
    {
        $html = self::pageWith(self::site(['shop.dxns' => self::ANNOTATED]), 'Order');
        self::assertStringContainsString('class="chip chip-deprecated"', $html);
        self::assertStringContainsString('use email', $html);
        self::assertStringContainsString('class="chip chip-read-only"', $html);
        self::assertStringContainsString('<em>receipts</em>', $html);
    }

    public function testAnEnumsValuesAreListedWithTheirOwnNotes(): void
    {
        $html = self::pageWith(self::site([
            'c.dxns' => '%{Color: {:doc {:enum :red :old} %{values: %{:old => %{deprecated: true, description: "Use red"}}}}}',
        ]), 'Color');
        self::assertStringContainsString('<dl class="values">', $html);
        self::assertStringContainsString('Use red', $html);
        self::assertStringContainsString('<code>:old</code><span class="chip chip-deprecated">', $html);
    }
}
