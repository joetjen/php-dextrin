<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Bin;

use PHPUnit\Framework\TestCase;

/**
 * `dextrin check` -- a tree of `.dxns` checked; what compiling finds
 * deprecated is printed as a warning (G10 of dextrin's
 * design/typespec.md), and fails the check under `--warnings-as-errors`.
 * As the reference's `mix dextrin.check` test.
 */
final class CheckCommandTest extends TestCase
{
    private const DEPRECATED_USE = <<<'DXN'
        %{
          Old: {:doc %schema{x: :integer} %{deprecated: "use New"}}
          Holder: %schema{old: Old}
        }
        DXN;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dextrin-check-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function tree(string $source): string
    {
        file_put_contents("{$this->dir}/acme.dxns", $source);
        return $this->dir;
    }

    /**
     * @param list<string> $args
     * @return array{int, string} exit status and everything printed
     */
    private static function check(array $args): array
    {
        exec(
            escapeshellarg(PHP_BINARY) . ' bin/dextrin check ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1',
            $out,
            $status,
        );
        return [$status, implode("\n", $out)];
    }

    public function testATreeWithNothingDeprecatedInUsePassesQuietly(): void
    {
        [$status, $out] = self::check([$this->tree('%{A: %schema{x: :integer}}')]);
        self::assertSame(0, $status, $out);
        self::assertStringContainsString('OK: 1 files', $out);
        self::assertStringNotContainsString('warning', $out);
    }

    public function testAUseOfSomethingDeprecatedIsAWarningAndTheCheckPasses(): void
    {
        [$status, $out] = self::check([$this->tree(self::DEPRECATED_USE)]);
        self::assertSame(0, $status, $out);
        self::assertStringContainsString('warning: acme/Holder uses acme/Old(1), which is deprecated: use New', $out);
        self::assertStringContainsString('OK: 1 files', $out);
    }

    public function testWarningsAsErrorsFailsTheCheckOnOne(): void
    {
        [$status, $out] = self::check([$this->tree(self::DEPRECATED_USE), '--warnings-as-errors']);
        self::assertSame(1, $status, $out);
        self::assertStringContainsString('1 warning', $out);
    }
}
