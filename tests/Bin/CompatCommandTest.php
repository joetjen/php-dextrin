<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Bin;

use PHPUnit\Framework\TestCase;

/**
 * `dextrin compat OLD NEW` -- the version discipline check, for CI.
 */
final class CompatCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dextrin-compat-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param array<string, string> $files */
    private function schemas(string $name, array $files): string
    {
        $path = "{$this->dir}/{$name}";
        mkdir($path);
        foreach ($files as $file => $source) {
            file_put_contents("{$path}/{$file}", $source);
        }
        return $path;
    }

    /** @return array{int, string} exit status and everything printed */
    private static function compat(string $old, string $new): array
    {
        exec(
            escapeshellarg(PHP_BINARY) . ' bin/dextrin compat ' . escapeshellarg($old) . ' ' . escapeshellarg($new) . ' 2>&1',
            $out,
            $status,
        );
        return [$status, implode("\n", $out)];
    }

    public function testPassesListingWhatTheMaterializerHasToUpgrade(): void
    {
        $old = $this->schemas('old', ['user.dxns' => '%{User: %schema{name: :string}}']);
        $new = $this->schemas('new', [
            'user.dxns' => '%{User: %schema{name: :string}}',
            'user-2.dxns' => '%{User: %schema(2){name: :string, email?: :string}}',
        ]);
        [$status, $out] = self::compat($old, $new);
        self::assertSame(0, $status, $out);
        self::assertStringContainsString('User 1 -> 2: added email', $out);
        self::assertStringContainsString('OK: no version discipline problems', $out);
    }

    public function testFailsOnAVersionWhoseContentChangedWithoutANewVersion(): void
    {
        $old = $this->schemas('old', ['user.dxns' => '%{User: %schema{name: :string}}']);
        $new = $this->schemas('new', ['user.dxns' => '%{User: %schema{name: :integer}}']);
        [$status, $out] = self::compat($old, $new);
        self::assertSame(1, $status, $out);
        self::assertStringContainsString('User version 1 changed without a new version', $out);
    }

    public function testTakesASingleFileAsWellAsADirectory(): void
    {
        file_put_contents("{$this->dir}/old.dxns", '%{User: %schema(2){name: :string}}');
        file_put_contents("{$this->dir}/new.dxns", '%{User: %schema{name: :string}}');
        [$status, $out] = self::compat("{$this->dir}/old.dxns", "{$this->dir}/new.dxns");
        self::assertSame(1, $status, $out);
        self::assertStringContainsString('User went down from version 2 to 1', $out);
    }
}
