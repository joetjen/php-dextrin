<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\Std;
use PHPUnit\Framework\TestCase;

final class StdTest extends TestCase
{
    public function testSourceReadsTheDxnsFile(): void
    {
        $source = Std::source();
        self::assertStringContainsString('PositiveInteger', $source);
    }

    public function testRegistryCompilesEveryStandardNamedType(): void
    {
        $registry = Std::registry();
        $names = ['PositiveInteger', 'NonNegativeInteger', 'NegativeInteger', 'NonPositiveInteger',
            'PositiveFloat', 'NonNegativeFloat', 'Percentage', 'NonEmptyString', 'NonEmptyList', 'NonEmptySet'];
        foreach ($names as $name) {
            self::assertNotNull($registry->fetchTypeAlias($name), "expected {$name} to be defined");
        }
    }

    public function testPositiveIntegerRejectsZeroAndBelow(): void
    {
        $type = Std::registry()->fetchTypeAlias('PositiveInteger');
        self::assertNotNull($type);
        self::assertTrue($type->matches(1));
        self::assertFalse($type->matches(0));
        self::assertFalse($type->matches(-1));
    }

    public function testNonEmptyStringRejectsTheEmptyString(): void
    {
        $type = Std::registry()->fetchTypeAlias('NonEmptyString');
        self::assertNotNull($type);
        self::assertTrue($type->matches('a'));
        self::assertFalse($type->matches(''));
    }

    public function testPercentageEnforcesBothBounds(): void
    {
        $type = Std::registry()->fetchTypeAlias('Percentage');
        self::assertNotNull($type);
        self::assertTrue($type->matches(50.0));
        self::assertFalse($type->matches(-0.1));
        self::assertFalse($type->matches(100.1));
    }

    public function testRegistryMergesIntoAnExistingBaseRegistry(): void
    {
        $base = (new Registry())->putTag('my-tag', static fn (mixed $v): mixed => $v);
        $merged = Std::registry($base);
        self::assertNotNull($merged->fetchTag('my-tag'), 'the caller\'s own registry state must survive merging in Std');
        self::assertNotNull($merged->fetchTypeAlias('PositiveInteger'));
    }
}
