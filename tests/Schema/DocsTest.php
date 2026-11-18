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

    public function testAPageNamesNoToolInItsMarkup(): void
    {
        foreach (self::site(['a.dxns' => '%{T: :string}']) as $html) {
            self::assertDoesNotMatchRegularExpression('/claude/i', $html);
        }
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

    public function testTheExampleBuilds(): void
    {
        // index, acme, acme.billing, acme.common, acme.events, acme.shop, style.css
        self::assertCount(7, Docs::site(__DIR__ . '/../../example/schemas', 'Acme schemas'));
    }

    // ---- what DXNS gained since 2026-10-20 (as dextrin T696) -------------------

    public function testANamedTypesVersionsAreShownAsASchemasAreLatestFirst(): void
    {
        $html = self::pageWith(self::site([
            'a/Tier.dxns' => '%{Tier: {:enum :bronze :silver}}',
            'a/Tier-v2.dxns' => '%{Tier: %type(2)[{:enum :bronze :silver :gold}]}',
        ]), 'Tier');
        self::assertStringContainsString('<span class="chip chip-version" id="Tier-v2">v2</span>', $html);
        self::assertStringContainsString('<details class="older" id="Tier-v1">', $html);
        self::assertStringContainsString(':gold', $html);
        self::assertStringNotContainsString('%type', $html);
    }

    public function testAPinnedReferenceLinksToTheVersionItPinsInEitherSpelling(): void
    {
        $pages = self::site([
            'a/Tier.dxns' => '%{Tier: {:enum :bronze}}',
            'a/Tier-v2.dxns' => '%{Tier: %type(2)[{:enum :bronze :gold}]}',
            'a.dxns' => '%{C: %shape{t: a/Tier(1) u: {:at a/Tier 2}}}',
        ]);
        $html = $pages['a/index.html'];
        self::assertStringContainsString('<a class="ref" href="../a/index.html#Tier-v1">a/Tier(1)</a>', $html);
        self::assertStringContainsString('<a class="ref" href="../a/index.html#Tier-v2">', $html);
        self::assertStringContainsString('Used by', self::pageWith($pages, 'Tier'));
    }

    public function testAHeadsBasesAreLinksAndADroppedFieldAndTheExtrasAreRows(): void
    {
        $html = self::site(['a.dxns' => <<<'DXN'
            %{
              P: %shape{name: :string nick: :string}
              C: %schema(1) includes P {
                - nick
                id: :uuid
                * :string => :integer
                - *
              }
            }
            DXN])['a/index.html'];
        self::assertStringContainsString('<p class="bases">includes <a class="ref" href="../a/index.html#P">P</a></p>', $html);
        self::assertStringContainsString('<tr class="dropped">', $html);
        self::assertStringContainsString('<s>nick</s>', $html);
        self::assertStringContainsString('<code>* <span class="kw">:string</span></code>', $html);
        self::assertStringContainsString('<tr class="closed">', $html);
    }

    public function testACommentOnALeftOutFieldTheExtrasOrTheClosingIsItsNote(): void
    {
        $pages = self::site([
            'a.dxns' => <<<'DXN'
                %{
                  P: %shape{a: :string nick: :string}
                  C: %shape includes P {
                    # why nick goes
                    - nick
                    # about id
                    id: :uuid
                    * :string => :integer # about the rest
                    - * # nothing else
                  }
                }
                DXN,
        ]);
        $html = $pages['a/index.html'];
        self::assertStringContainsString('<td class="notes">left out of the bases<p>why nick goes</p></td>', $html);
        self::assertStringContainsString('<td class="notes"><p>about id</p></td>', $html);
        self::assertStringContainsString('<td class="notes">any other entry with such a key<p>about the rest</p></td>', $html);
        self::assertStringContainsString('<td class="notes">no entries beyond these<p>nothing else</p></td>', $html);
    }

    public function testADeprecatedVersionIsStruckThroughWithItsReason(): void
    {
        $html = self::pageWith(self::site([
            'a/C-v1.dxns' => '%{C: {:doc %schema{a: :string} %{deprecated: "use 2"}}}',
            'a/C-v2.dxns' => '%{C: %schema(2){a: :string b?: {:doc :string %{deprecated: true}}}}',
        ]), 'C');
        self::assertStringContainsString('<summary><s>Version 1</s> <span class="chip chip-deprecated" title="use 2">deprecated: use 2</span>', $html);
        self::assertStringContainsString('<code><s>b</s></code>', $html);
    }

    public function testAGenericsParametersAreListedWithTheirBoundsAndDefaults(): void
    {
        $html = self::site([
            'a.dxns' => '%{Page: {:generic [T {S :integer :int32}] %shape{items: {:list-of T}}}}',
        ])['a/index.html'];
        self::assertStringContainsString('<span class="chip chip-type">generic type</span>', $html);
        self::assertStringContainsString('<dl class="params">', $html);
        self::assertStringContainsString('<dt><code>S</code></dt><dd>within <code><span class="kw">:integer</span></code>, default <code><span class="kw">:int32</span></code></dd>', $html);
        self::assertStringNotContainsString(':generic', $html);
    }

    public function testAGenericInAVersionedFileTakesTheFilesVersion(): void
    {
        $html = self::pageWith(self::site([
            'a/Page.dxns' => '%{Page: {:generic [T] {:list-of T}}}',
            'a/Page-v2.dxns' => '%{Page: {:generic [T] %shape{items: {:list-of T}}}}',
        ]), 'Page');
        self::assertStringContainsString('<span class="chip chip-version" id="Page-v2">v2</span>', $html);
        self::assertStringContainsString('<details class="older" id="Page-v1">', $html);
    }

    public function testAStructsVersionIsItsOwnNeverABasesPin(): void
    {
        $html = self::site(['a.dxns' => '%{Q: %shape{x: :string} C: %schema includes Q(2) {y: :string}}'])['a/index.html'];
        self::assertStringContainsString('<span class="chip chip-version" id="C-v1">v1</span>', $html);
    }

    public function testDiscriminatorSecretKeyVisibilityAndMessagesAreShown(): void
    {
        $html = self::site(['a.dxns' => <<<'DXN'
            %{
              P: {:doc {:one-of A B} %{discriminator: :kind}}
              A: %shape{kind: {:const "a"}}
              B: %shape{kind: {:const "b"}}
              S: %shape{
                id: {:doc :string %{key: true}}
                token: {:doc :string %{secret: true visibility: [:create :read]}}
                code: {:doc {:refine :string %{min-length: 2}} %{messages: %{min-length: "too short"}}}
              }
            }
            DXN])['a/index.html'];
        self::assertStringContainsString('<span class="chip chip-discriminator">discriminator: kind</span>', $html);
        self::assertStringContainsString('<span class="chip chip-key">key</span>', $html);
        self::assertStringContainsString('<span class="chip chip-secret">secret</span>', $html);
        self::assertStringContainsString('<span class="chip chip-visibility">visible on create, read</span>', $html);
        self::assertStringContainsString('<dl class="messages">', $html);
        self::assertStringContainsString('too short', $html);
    }

    public function testAComputedDefaultIsShownAsOne(): void
    {
        $html = self::site(['a.dxns' => '%{S: %schema{at: %field{type: :timestamp computed: :now}}}'])['a/index.html'];
        self::assertStringContainsString('<span class="label">computed</span> <code><span class="kw">:now</span></code>', $html);
    }

    public function testAGeneratedFileSaysWhatGeneratedItAndThatIsNotItsProse(): void
    {
        $html = self::site([
            'a.dxns' => "# Generated by some-tool from a.tsp; do not edit.\n\n# About a.\n\n%{T: :string}",
        ])['a/index.html'];
        self::assertStringContainsString('<span class="provenance">generated by <code>some-tool</code> from <code>a.tsp</code></span>', $html);
        self::assertStringContainsString('About a.', $html);
        self::assertStringNotContainsString('do not edit', $html);
    }
}
