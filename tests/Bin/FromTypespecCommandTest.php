<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Bin;

use PHPUnit\Framework\TestCase;

/**
 * `dextrin from-typespec MAIN.tsp [--out DIR]` -- TypeSpec to DXNS by
 * TypeSpec's own compiler with the `@joetjen/dextrin-typespec` emitter,
 * through npx, as `mix dextrin.from_typespec` runs it. A fake `npx`,
 * first on the PATH, records the arguments it is given.
 */
final class FromTypespecCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dextrin-npx-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function fakeNpx(int $status): void
    {
        $log = escapeshellarg("{$this->dir}/args.txt");
        file_put_contents("{$this->dir}/npx", "#!/bin/sh\nprintf '%s\\n' \"\$@\" > {$log}\nexit {$status}\n");
        chmod("{$this->dir}/npx", 0o755);
    }

    /** @return array{int, string, string} exit status, standard output, standard error */
    private function command(string $path, string ...$args): array
    {
        $process = proc_open(
            [PHP_BINARY, 'bin/dextrin', 'from-typespec', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => $path],
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testRunsTspCompileWithTheEmitterWritingIntoOut(): void
    {
        $this->fakeNpx(0);
        [$status, $out, $err] = $this->command("{$this->dir}:" . getenv('PATH'), 'main.tsp', '--out', '/tmp/x');
        self::assertSame(0, $status, $err);
        self::assertStringContainsString('wrote /tmp/x', $out);
        self::assertSame(
            ['--no-install', 'tsp', 'compile', 'main.tsp', '--emit', '@joetjen/dextrin-typespec',
                '--option', '@joetjen/dextrin-typespec.emitter-output-dir=/tmp/x'],
            explode("\n", trim((string) file_get_contents("{$this->dir}/args.txt"))),
        );
    }

    public function testOutDefaultsToSchemasMadeAbsolute(): void
    {
        $this->fakeNpx(0);
        [$status, $out, $err] = $this->command("{$this->dir}:" . getenv('PATH'), 'main.tsp');
        self::assertSame(0, $status, $err);
        $expected = getcwd() . '/schemas';
        self::assertStringContainsString("wrote {$expected}", $out);
        self::assertStringContainsString("emitter-output-dir={$expected}", (string) file_get_contents("{$this->dir}/args.txt"));
    }

    public function testFailsWhenTheCompileFails(): void
    {
        $this->fakeNpx(2);
        [$status, , $err] = $this->command("{$this->dir}:" . getenv('PATH'), 'main.tsp');
        self::assertSame(1, $status);
        self::assertStringContainsString('tsp compile failed (exit 2)', $err);
    }

    public function testFailsSayingWhatItNeedsWhenNpxIsNotThere(): void
    {
        [$status, , $err] = $this->command($this->dir, 'main.tsp');
        self::assertSame(1, $status);
        self::assertStringContainsString('npx was not found: TypeSpec needs Node 22 or later, with npx on the PATH', $err);
    }

    public function testTakesOneMainFile(): void
    {
        [$status, , $err] = $this->command($this->dir);
        self::assertSame(1, $status);
        self::assertStringContainsString('usage: dextrin from-typespec MAIN.tsp [--out DIR]', $err);
    }
}
