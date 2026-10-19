<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Bin;

use PHPUnit\Framework\TestCase;

/**
 * `dextrin format PATH` -- pretty keeps every comment beside its entry;
 * condensed output is one line, which no comment fits on.
 */
final class FormatCommandTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/dextrin-format-' . bin2hex(random_bytes(6)) . '.dxn';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function format(string ...$options): string
    {
        exec(escapeshellarg(PHP_BINARY) . ' bin/dextrin format ' . escapeshellarg($this->path) . ' ' . implode(' ', array_map('escapeshellarg', $options)) . ' 2>&1', $out);
        return implode("\n", $out);
    }

    public function testPrettyKeepsEveryCommentBesideItsEntry(): void
    {
        file_put_contents($this->path, "# the config\n%{name: \"acme\", # the name\n deps: [\"a\"]}\n");
        self::assertSame("# the config\n%{\n  name: \"acme\"  # the name\n  deps: [\n    \"a\"\n  ]\n}", $this->format());
    }

    public function testCondenseDropsCommentsACommentEndingItsLine(): void
    {
        file_put_contents($this->path, "%{name: \"acme\"} # the name\n");
        self::assertSame('%{name:"acme"}', $this->format('--mode', 'condense'));
    }
}
