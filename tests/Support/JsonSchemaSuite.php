<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Support;

use JOetjen\Dextrin\Convert\JsonObject;
use JOetjen\Dextrin\Convert\JsonSchema;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;

/**
 * Runs the official JSON-Schema-Test-Suite against the JSON Schema
 * converter (T674 of dextrin's design/json-schema.md): each case's schema
 * converted to DXNS and compiled, each test's instance read as DXN and
 * checked against the root. As the reference's test/support/
 * json_schema_suite.ex.
 *
 * The suite is the reference's copy, in dextrin beside this repository
 * (`../dextrin/test/fixtures/json-schema-test-suite`), not a copy of our
 * own. A reference to the suite's `http://localhost:1234/` is the file of
 * that path in its `remotes`, given to the converter beside the schema;
 * nothing is fetched.
 */
final class JsonSchemaSuite
{
    /** The draft directories the suite has, with the draft each is. */
    public const DRAFTS = [
        'draft4' => '04',
        'draft6' => '06',
        'draft7' => '07',
        'draft2019-09' => '2019-09',
        'draft2020-12' => '2020-12',
    ];

    private function __construct()
    {
    }

    /** The reference's dextrin repository, beside this one, or `null` when it is not there. */
    public static function reference(): ?string
    {
        $dir = dirname(__DIR__, 3) . '/dextrin';
        return is_dir("{$dir}/test/fixtures/json-schema-test-suite/tests") ? $dir : null;
    }

    private static function root(): string
    {
        return self::reference() . '/test/fixtures/json-schema-test-suite';
    }

    /**
     * The reference's list of the tests the converter does not pass, by id.
     *
     * @return array<string, true>
     */
    public static function exceptions(string $file): array
    {
        $listed = [];
        foreach (explode("\n", (string) file_get_contents($file)) as $line) {
            if (str_starts_with($line, 'draft')) {
                $listed[rtrim($line, "\r")] = true;
            }
        }
        return $listed;
    }

    /**
     * Every required test of `$dir`, case by case: the case's schema and
     * its tests, each `[id, instance, valid]`.
     *
     * @return list<array{schema: mixed, tests: list<array{0: string, 1: mixed, 2: bool}>}>
     */
    public static function cases(string $dir): array
    {
        $files = glob(self::root() . "/tests/{$dir}/*.json") ?: [];
        sort($files, SORT_STRING);
        $cases = [];
        foreach ($files as $file) {
            $decoded = JsonSchema::decodeJson((string) file_get_contents($file));
            foreach (is_array($decoded) ? $decoded : [] as $case) {
                if (!$case instanceof JsonObject) {
                    continue;
                }
                $tests = [];
                foreach ((array) $case->get('tests') as $test) {
                    if ($test instanceof JsonObject) {
                        $id = "{$dir}/" . basename($file, '.json') . ": {$case->get('description')}: {$test->get('description')}";
                        $tests[] = [$id, $test->get('data'), $test->get('valid') === true];
                    }
                }
                $cases[] = ['schema' => $case->get('schema'), 'tests' => $tests];
            }
        }
        return $cases;
    }

    /**
     * `$schema` converted and compiled as draft `$draft`, or why it is not:
     * a reference to the suite's remotes is answered from that directory.
     *
     * @param list<array{0: string, 1: mixed}> $remotes
     */
    public static function compiled(mixed $schema, string $draft, array $remotes = []): Registry|string
    {
        try {
            ['document' => $doc] = JsonSchema::toDxns([['http://localhost:1234/root.json', $schema], ...$remotes], ['draft' => $draft, 'name' => 'Root']);
        } catch (\Throwable $err) {
            $why = $err->getMessage();
            if (preg_match('~resolved: http://localhost:1234/([^#\s)]+)~', $why, $m) === 1) {
                $file = self::root() . '/remotes/' . $m[1];
                $given = array_filter($remotes, static fn (array $r): bool => str_contains($r[0], $m[1]));
                if (is_file($file) && $given === []) {
                    $remote = JsonSchema::decodeJson((string) file_get_contents($file));
                    return self::compiled($schema, $draft, [...$remotes, ['http://localhost:1234/' . $m[1], $remote]]);
                }
            }
            return $why;
        }
        try {
            return Schema::compile($doc, new Registry());
        } catch (\Throwable $err) {
            return "compile: {$err->getMessage()}";
        }
    }

    /** Whether `$instance` passes the compiled schema. */
    public static function takes(Registry $registry, mixed $instance): bool
    {
        try {
            Schema::check(JsonSchema::jsonValue($instance), $registry, 'Root');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
