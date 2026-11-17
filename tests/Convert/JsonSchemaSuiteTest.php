<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Convert;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Tests\Support\JsonSchemaSuite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parity test of the JSON Schema converter (T674 of dextrin's
 * design/json-schema.md, Q7): every required test of the official
 * JSON-Schema-Test-Suite, for drafts 4, 6, 7, 2019-09 and 2020-12, passes
 * once converted to DXNS -- except those the reference lists, by reason,
 * in its test/fixtures/json-schema-exceptions.txt, and those listed in
 * tests/Fixtures/json-schema-exceptions-php.txt here, when that file
 * exists. A listed test that passes fails this too, so the lists stay
 * true. As the reference's json_schema_suite_test.exs.
 *
 * The suite and the reference's list are read from dextrin beside this
 * repository (`../dextrin`); without it, this is skipped.
 */
final class JsonSchemaSuiteTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function drafts(): iterable
    {
        foreach (JsonSchemaSuite::DRAFTS as $dir => $draft) {
            yield $dir => [$dir, $draft];
        }
    }

    #[DataProvider('drafts')]
    public function testTheSuitePassesButForTheListedExceptions(string $dir, string $draft): void
    {
        $reference = JsonSchemaSuite::reference();
        if ($reference === null) {
            self::markTestSkipped(
                'the JSON-Schema-Test-Suite is read from the reference implementation, dextrin, '
                . 'checked out beside this repository (../dextrin); it is not there',
            );
        }
        $listed = JsonSchemaSuite::exceptions("{$reference}/test/fixtures/json-schema-exceptions.txt");
        $ours = dirname(__DIR__) . '/Fixtures/json-schema-exceptions-php.txt';
        if (is_file($ours)) {
            $listed += JsonSchemaSuite::exceptions($ours);
        }
        $failing = [];
        $fixed = [];
        $count = 0;
        foreach (JsonSchemaSuite::cases($dir) as $case) {
            $registry = JsonSchemaSuite::compiled($case['schema'], $draft);
            foreach ($case['tests'] as [$id, $data, $valid]) {
                $count++;
                $passed = $registry instanceof Registry && JsonSchemaSuite::takes($registry, $data) === $valid;
                if (!$passed && !isset($listed[$id])) {
                    $failing[] = $id . (is_string($registry) ? " ({$registry})" : '');
                }
                if ($passed && isset($listed[$id])) {
                    $fixed[] = $id;
                }
            }
        }
        self::assertGreaterThan(0, $count, "no tests found for {$dir}");
        self::assertSame([], $failing, "failing, not listed:\n" . implode("\n", $failing));
        self::assertSame([], $fixed, "listed, but passing now:\n" . implode("\n", $fixed));
    }
}
