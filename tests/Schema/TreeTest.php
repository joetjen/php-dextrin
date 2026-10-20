<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\FileResolver;
use JOetjen\Dextrin\Schema\Tree;
use PHPUnit\Framework\TestCase;

/**
 * A tree of `.dxns` files read as one, as dextrin 96d3fc0 (Jan,
 * 2026-10-20): every entry named from its file's path (DXN.md §4.6), the
 * files compiled in the order their references need, and every problem
 * reported with its file and line.
 */
final class TreeTest extends TestCase
{
    private const GOOD = [
        'acme.common.dxns' => <<<'DXN'
            %{
              Money: %shape{amount: :decimal, currency: Currency}
              Currency: {:enum "EUR" "USD"}
              Percentage: {:refine :decimal %{min: 0, max: 100}}
            }
            DXN,
        'acme/shop/Order-v2.dxns' => <<<'DXN'
            %{
              Order: %schema(2){
                total: acme.common/Money
                discount?: acme.common/Percentage
                lines: {:list-of Line}
              }
              Line: %shape{sku: :string}
            }
            DXN,
        'HealthCheck.dxns' => '%{HealthCheck: %schema{ok: :boolean}}',
    ];

    /** @param array<string, string> $files */
    private static function tree(array $files): string
    {
        $root = sys_get_temp_dir() . '/dextrin-tree-' . getmypid() . '-' . bin2hex(random_bytes(4));
        foreach ($files as $path => $source) {
            $full = "{$root}/{$path}";
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0o777, true);
            }
            file_put_contents($full, $source);
        }
        return $root;
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private static function messages(array $result): array
    {
        self::assertFalse($result['ok']);
        /** @var list<array{message: string}> $problems */
        $problems = $result['problems'];
        return array_map(static fn (array $p): string => $p['message'], $problems);
    }

    public function testATreeInOrderLoadsEveryEntryUnderItsFullName(): void
    {
        $result = Tree::load([self::tree(self::GOOD)]);
        self::assertTrue($result['ok']);
        self::assertSame(['files' => 3, 'definitions' => 6], $result['summary']);
        self::assertArrayHasKey('acme.shop/Order', $result['registry']->structSchemas());
        self::assertNotNull($result['registry']->fetchTypeAlias('acme.common/Money'));
    }

    public function testAnotherFilesNamedTypesResolveWhateverOrderTheFilesSortIn(): void
    {
        $result = Tree::load([self::tree(self::GOOD)]);
        self::assertTrue($result['ok']);
        $registry = $result['registry'];
        Dextrin::decode('%acme.shop/Order(2){total: %{amount: 1.50M, currency: "EUR"}, discount: 10.0M, lines: [%{sku: "a"}]}', $registry);
        $this->expectException(DXNError::class);
        Dextrin::decode('%acme.shop/Order(2){total: %{amount: 1.50M, currency: "GBP"}, lines: []}', $registry);
    }

    public function testTheFileResolverResolvesAnotherFilesNamedTypes(): void
    {
        $registry = (new Registry())->putResolver(FileResolver::forPaths([self::tree(self::GOOD)]));
        Dextrin::decode('%acme.shop/Order(2){total: %{amount: 1.50M, currency: "EUR"}, discount: 10.0M, lines: []}', $registry);
        $this->expectException(DXNError::class);
        Dextrin::decode('%acme.shop/Order(2){total: %{amount: 1.50M, currency: "GBP"}, lines: []}', $registry);
    }

    public function testASyntaxErrorWithItsLine(): void
    {
        $result = Tree::load([self::tree(['a.dxns' => "%{\n  A: :integer\n  B: [\n}\n"])]);
        self::assertFalse($result['ok']);
        self::assertCount(1, $result['problems']);
        self::assertSame('a.dxns', $result['problems'][0]['file']);
    }

    public function testADefinitionThatDoesNotCompileWithItsLine(): void
    {
        $result = Tree::load([self::tree(['a.dxns' => "%{\n  A: :integer\n  B: {:wibble :integer}\n}\n"])]);
        self::assertFalse($result['ok']);
        self::assertSame([['a.dxns', 3]], array_map(static fn (array $p): array => [$p['file'], $p['line']], $result['problems']));
    }

    public function testANameThatResolvesToNothingUnlessItsNamespaceIsExtern(): void
    {
        $messages = self::messages(Tree::load([self::tree(['a.dxns' => '%{A: %schema{m: acme.comon/Money, n: Nowhere}}'])]));
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'acme.comon/Money')));
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'Nowhere')));

        $root = self::tree(['a.dxns' => '%{A: %schema{m: other/Thing}}']);
        self::assertFalse(Tree::load([$root])['ok']);
        self::assertTrue(Tree::load([$root], ['other'])['ok']);
    }

    public function testAVersionedFileHoldingAnotherVersionAndOneVersionInTwoFiles(): void
    {
        self::assertStringContainsString('version', self::messages(Tree::load([self::tree(['Customer-v2.dxns' => '%{Customer: %schema(3){name: :string}}'])]))[0]);
        $twice = self::messages(Tree::load([self::tree([
            'Customer-v2.dxns' => '%{Customer: %schema(2){name: :string}}',
            'Customer/v2.dxns' => '%{Customer: %schema(2){name: :string}}',
        ])]));
        self::assertCount(1, $twice);
        self::assertStringContainsString('two files', $twice[0]);
    }

    public function testEveryFilesProblemsAndFilesThatNeedEachOther(): void
    {
        $all = Tree::load([self::tree(['a.dxns' => '%{A: {:wibble :integer}}', 'b.dxns' => '%{B: [}'])]);
        self::assertFalse($all['ok']);
        $files = array_map(static fn (array $p): string => $p['file'], $all['problems']);
        sort($files);
        self::assertSame(['a.dxns', 'b.dxns'], $files);

        $cycle = self::messages(Tree::load([self::tree(['a.dxns' => '%{A: {:list-of b/B}}', 'b.dxns' => '%{B: {:list-of a/A}}'])]));
        self::assertCount(1, $cycle);
        self::assertStringContainsString('a.dxns', $cycle[0]);
        self::assertStringContainsString('b.dxns', $cycle[0]);
    }
}
