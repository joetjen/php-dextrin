<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Conformance;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNTimestamp;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUuid;
use JOetjen\Dextrin\Value\Equality;
use PHPUnit\Framework\TestCase;

/**
 * `dxn/DXN.md` §3's own worked example -- every construct in it is
 * fully specified by §1.2 + §1.3, and every field should round-trip
 * through this port's text codec without loss (binary round-trip
 * lands with the `.dxnb` phase).
 */
final class WorkedExampleTest extends TestCase
{
    private const DOC = <<<'DXN'
        @dxn "1.0"
        %{
          id:      @uuid "550e8400-e29b-41d4-a716-446655440000"
          name:    "Ada Lovelace"
          active:  true
          score:   19.99M
          tags:    @{:admin :staff}
          meta:    @ordered %{created: ~U[1990-01-01 00:00:00Z]}
          address: %Point[51.05, 13.74]
          result:  {:ok, 200}
          handle:  ~r/^[a-z0-9_]{3,20}$/i
        }
        DXN;

    public function testDecodesEveryFieldToTheExpectedShape(): void
    {
        $value = Dextrin::decode(self::DOC);

        self::assertIsArray($value);
        self::assertEquals(DXNUuid::fromString('550e8400-e29b-41d4-a716-446655440000'), $value['id']);
        self::assertSame('Ada Lovelace', $value['name']);
        self::assertTrue($value['active']);
        self::assertEquals(DXNDecimal::fromString('19.99'), $value['score']);
        self::assertEquals(new DXNSet([new DXNKeyword('admin'), new DXNKeyword('staff')]), $value['tags']);
        self::assertInstanceOf(DXNOrderedMap::class, $value['meta']);
        self::assertInstanceOf(DXNTimestamp::class, $value['meta']->pairs[0][1]);
        self::assertInstanceOf(DXNStruct::class, $value['address']);
        self::assertSame([51.05, 13.74], $value['address']->values());
        self::assertEquals(new DXNTuple([new DXNKeyword('ok'), 200]), $value['result']);
        self::assertInstanceOf(DXNRegex::class, $value['handle']);
        self::assertSame('^[a-z0-9_]{3,20}$', $value['handle']->pattern);
        self::assertTrue($value['handle']->flags['i']);
    }

    public function testCompactEncodeDecodesBackToAnEqualValue(): void
    {
        $value = Dextrin::decode(self::DOC);
        $reencoded = Dextrin::encode($value);
        $roundTripped = Dextrin::decode($reencoded);
        self::assertTrue(Equality::dxnEquals($value, $roundTripped));
    }

    public function testPrettyEncodeDecodesBackToAnEqualValue(): void
    {
        $value = Dextrin::decode(self::DOC);
        $pretty = Dextrin::encode($value, pretty: true);
        $roundTripped = Dextrin::decode($pretty);
        self::assertTrue(Equality::dxnEquals($value, $roundTripped));
    }

    public function testPrettyOutputIsActuallyMultiLine(): void
    {
        $value = Dextrin::decode(self::DOC);
        $pretty = Dextrin::encode($value, pretty: true);
        self::assertGreaterThan(1, substr_count($pretty, "\n"));
    }
}
