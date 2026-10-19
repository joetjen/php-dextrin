<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use PHPUnit\Framework\TestCase;

/**
 * B8 of `design/implementation-plan.md`: the version discipline check. A
 * schema whose content changed needs a new version, a version never goes
 * down, and the latest version lists which older ones its materializer
 * has to upgrade, with what changed.
 */
final class CompatTest extends TestCase
{
    private const V1 = '%{User: %schema{name: :string}}';
    private const V2 = '%{User: %schema(2){name: :string, email?: :string}}';

    /** @param list<string> $sources */
    private static function loaded(array $sources): Registry
    {
        return array_reduce(
            $sources,
            static fn (Registry $registry, string $source) => Schema::compile(Dextrin::decode($source), $registry),
            new Registry(),
        );
    }

    public function testTheSameSchemasPassWithNothingToReport(): void
    {
        self::assertSame(
            ['problems' => [], 'upgrades' => [['schema' => 'User', 'from' => 1, 'to' => 2, 'added' => ['email'], 'removed' => [], 'changed' => []]]],
            Schema::compat(self::loaded([self::V1, self::V2]), self::loaded([self::V1, self::V2])),
        );
    }

    public function testANewVersionPasses(): void
    {
        self::assertSame([], Schema::compat(self::loaded([self::V1]), self::loaded([self::V1, self::V2]))['problems']);
    }

    public function testAVersionWhoseContentChangedWithoutANewVersionIsAProblem(): void
    {
        $changed = '%{User: %schema{name: :string, nick?: :string}}';
        self::assertSame(
            [['schema' => 'User', 'version' => 1, 'problem' => 'changed-without-new-version']],
            Schema::compat(self::loaded([self::V1]), self::loaded([$changed]))['problems'],
        );
    }

    public function testAChangedTypeRequirednessOrDefaultIsAChangeADescriptionIsNot(): void
    {
        $base = '%{User: %schema{name: :string}}';
        foreach ([
            '%{User: %schema{name: :integer}}',
            '%{User: %schema{name?: :string}}',
            '%{User: %schema{name: %field{type: :string, default: "x"}}}',
        ] as $changed) {
            self::assertCount(1, Schema::compat(self::loaded([$base]), self::loaded([$changed]))['problems'], $changed);
        }
        $described = '%{User: %schema{name: %field{type: :string, description: "the name"}}}';
        self::assertSame([], Schema::compat(self::loaded([$base]), self::loaded([$described]))['problems']);
    }

    public function testAVersionThatWentDownIsAProblem(): void
    {
        self::assertSame(
            [['schema' => 'User', 'problem' => 'version-went-down', 'from' => 2, 'to' => 1]],
            Schema::compat(self::loaded([self::V1, self::V2]), self::loaded([self::V1]))['problems'],
        );
    }

    public function testTheUpgradeChecklistSaysWhatChangedSinceEachOlderVersion(): void
    {
        $v3 = '%{User: %schema(3){name: :integer, email?: :string, id: :uuid}}';
        self::assertSame(
            [
                ['schema' => 'User', 'from' => 1, 'to' => 3, 'added' => ['email', 'id'], 'removed' => [], 'changed' => ['name']],
                ['schema' => 'User', 'from' => 2, 'to' => 3, 'added' => ['id'], 'removed' => [], 'changed' => ['name']],
            ],
            Schema::compat(self::loaded([self::V1, self::V2]), self::loaded([self::V1, self::V2, $v3]))['upgrades'],
        );
    }

    public function testANewSchemaIsNotAProblem(): void
    {
        self::assertSame(
            [],
            Schema::compat(self::loaded([self::V1]), self::loaded([self::V1, '%{Other: %schema{x: :integer}}']))['problems'],
        );
    }
}
