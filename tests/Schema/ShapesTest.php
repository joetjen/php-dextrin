<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNMap;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

/**
 * `%shape{...}`, `{:case field %{...}}`, guarded recursion and generics,
 * as dextrin c8c5a75, 66c05e5 and 1434e79 (design/shapes-and-case.md,
 * Jan, 2026-10-20): a shape is a named, unversioned map type that fills
 * its defaults; a case chooses a field's type by a sibling field's value;
 * named types see their siblings and may recurse through a guard;
 * `check` and `conform` check a value without decoding it.
 */
final class ShapesTest extends TestCase
{
    private const EVENTS = <<<'DXN'
        %{
          Envelope: %schema{
            type: :string
            payload: {:case type %{"placed" => OrderPlaced, "cancelled" => OrderCancelled, :else => :any}}
          }
          Strict: %schema{type: :string, payload: {:case type %{"placed" => OrderPlaced}}}
          Nested: %schema{type: :string, payloads: {:list-of {:nilable {:case type %{"placed" => OrderPlaced}}}}}
          Holder: %schema{v: OrderPlaced}
          OrderPlaced: %shape{
            order_id: :integer
            currency: %field{type: :string, default: "EUR"}
            gift?: :boolean
          }
          OrderCancelled: %shape{reason: CancelReason, email?: :never}
          CancelReason: {:enum :customer_request :out_of_stock}
        }
        DXN;

    private const RECURSIVE = <<<'DXN'
        %{
          Thread: %schema{root: Comment, value: JsonValue}
          Comment: %shape{text: :string, likes: %field{type: :integer, default: 0}, replies?: {:list-of Comment}}
          JsonValue: {:any-of :nil :boolean :integer :float :string {:list-of JsonValue} {:map-of :string JsonValue}}
        }
        DXN;

    private const GENERICS = <<<'DXN'
        %{
          Page: {:generic [t] %shape{items: {:list-of t}, next?: :string}}
          Pair: {:generic [a b] {:tuple-of a b}}
          Orders: {:of Page Order}
          Order: %schema{id: :integer}
          Feed: %schema{page: {:of Page :string}, pair: {:of Pair :integer :string}}
        }
        DXN;

    /** The DXN.md §5 category an error carries: decode errors and schema errors alike. */
    private static function categoryOf(\Throwable $err): ?string
    {
        return match (true) {
            $err instanceof HasCategory => $err->category(),
            $err instanceof DXNError => $err->category,
            default => null,
        };
    }

    private static function registry(string $text = self::EVENTS): Registry
    {
        return Schema::compile(Dextrin::decode($text), new Registry());
    }

    private static function compileCategory(string $text): ?string
    {
        try {
            self::registry($text);
            return null;
        } catch (\Throwable $err) {
            return self::categoryOf($err);
        }
    }

    /** Every map key by its name, so a test reads the same whatever kind of map came back. */
    private static function named(mixed $value): mixed
    {
        if ($value instanceof DXNMap) {
            $out = [];
            foreach ($value->pairs() as [$k, $v]) {
                $out[$k instanceof DXNKeyword ? $k->name : (string) $k] = self::named($v);
            }
            return $out;
        }
        if (is_array($value) && !array_is_list($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = MapBuilder::toDxnMapKey($k);
                $out[$key instanceof DXNKeyword ? $key->name : (string) $key] = self::named($v);
            }
            return $out;
        }
        if ($value instanceof DXNList) {
            return array_map(self::named(...), $value->items);
        }
        return $value;
    }

    /**
     * A decode's struct fields by name, or the category it refuses with.
     *
     * @return array<string, mixed>|string|null
     */
    private static function decoded(string $text, bool $strict = false): array|string|null
    {
        try {
            $struct = Dextrin::decode($text, self::registry(), $strict);
        } catch (\Throwable $err) {
            return self::categoryOf($err);
        }
        self::assertInstanceOf(DXNStruct::class, $struct);
        $out = [];
        foreach ($struct->pairs() as [$k, $v]) {
            $out[(string) $k] = self::named($v);
        }
        return $out;
    }

    public function testAShapeTypedFieldTakesAPlainMapWhoseFieldsMatch(): void
    {
        self::assertSame(['order_id' => 1, 'currency' => 'USD'], self::decoded('%Holder{v: %{order_id: 1, currency: "USD"}}')['v'] ?? null);
    }

    public function testAShapeFillsAMissingFieldsDefault(): void
    {
        self::assertSame(['order_id' => 1, 'currency' => 'EUR'], self::decoded('%Holder{v: %{order_id: 1}}')['v'] ?? null);
    }

    public function testAShapeRefusesAMapMissingARequiredFieldOrAFieldOfTheWrongType(): void
    {
        self::assertSame('schema/type-mismatch', self::decoded('%Holder{v: %{currency: "USD"}}'));
        self::assertSame('schema/type-mismatch', self::decoded('%Holder{v: %{order_id: "1"}}'));
    }

    public function testAShapeMatchesAFieldByAnySpellingAndRefusesItGivenTwice(): void
    {
        $decoded = self::decoded('%Holder{v: %{"order_id" => 1}}');
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['v']['order_id'] ?? null);
        self::assertSame('schema/type-mismatch', self::decoded('%Holder{v: %{order_id: 1, "order_id" => 2}}'));
    }

    public function testAShapeKeepsAnExtraKeyAndAStrictDecodeRefusesIt(): void
    {
        $decoded = self::decoded('%Holder{v: %{order_id: 1, note: "x"}}');
        self::assertIsArray($decoded);
        self::assertSame('x', $decoded['v']['note'] ?? null);
        self::assertSame('schema/type-mismatch', self::decoded('%Holder{v: %{order_id: 1, note: "x"}}', true));
    }

    public function testAShapeRefusesAStruct(): void
    {
        self::assertSame('schema/type-mismatch', self::decoded('%Holder{v: %OrderPlaced{order_id: 1}}'));
    }

    public function testAnOptionalFieldTypedNeverMustStayAbsent(): void
    {
        self::assertIsArray(self::decoded('%Envelope{type: "cancelled", payload: %{reason: :out_of_stock}}'));
        self::assertSame('schema/type-mismatch', self::decoded('%Envelope{type: "cancelled", payload: %{reason: :out_of_stock, email: "a"}}'));
    }

    public function testACaseChoosesTheTypeByTheSiblingFieldsValue(): void
    {
        $decoded = self::decoded('%Envelope{type: "placed", payload: %{order_id: 1}}');
        self::assertIsArray($decoded);
        self::assertSame('EUR', $decoded['payload']['currency'] ?? null);
        self::assertSame('schema/type-mismatch', self::decoded('%Envelope{type: "placed", payload: %{reason: :out_of_stock}}'));
    }

    public function testACaseTakesElseForAnyOtherValueAndRefusesWithoutOne(): void
    {
        $decoded = self::decoded('%Envelope{type: "other", payload: 42}');
        self::assertIsArray($decoded);
        self::assertSame(42, $decoded['payload']);
        self::assertSame('schema/type-mismatch', self::decoded('%Strict{type: "other", payload: %{order_id: 1}}'));
    }

    public function testACaseWorksAnywhereInAFieldsType(): void
    {
        $decoded = self::decoded('%Nested{type: "placed", payloads: [%{order_id: 1} nil]}');
        self::assertIsArray($decoded);
        self::assertSame([['order_id' => 1, 'currency' => 'EUR'], null], $decoded['payloads']);
    }

    public function testCompileRefusals(): void
    {
        self::assertSame('schema/compile', self::compileCategory('%{S: %schema{a: :string, b: {:case c %{1 => :any}}}}'));
        self::assertSame('schema/compile', self::compileCategory('%{S: %shape(2){a: :string}}'));
        self::assertSame('schema/compile', self::compileCategory('%{S: %shape[:string]}'));
        self::assertSame('schema/compile', self::compileCategory('%{A: {:nilable A}}'));
        self::assertSame('schema/compile', self::compileCategory('%{A: {:any-of B :integer}, B: {:not A}}'));
    }

    public function testNamedTypesSeeTheirSiblingsAndRecurseThroughAGuard(): void
    {
        self::assertNull(self::compileCategory('%{A: {:list-of B}, B: {:list-of C}, C: :integer}'));
        self::assertNull(self::compileCategory('%{A: {:list-of B}, B: {:nilable A}}'));
        self::assertNull(self::compileCategory('%{Comment: %shape{text: :string, replies: {:list-of Comment}}}'));
    }

    public function testRecursiveTypesCheckEveryLevelAndFillDefaultsAtEveryLevel(): void
    {
        $r = self::registry(self::RECURSIVE);
        $thread = Dextrin::decode('%Thread{root: %{text: "a", replies: [%{text: "b", replies: [%{text: "c"}]}]}, value: 1}', $r);
        self::assertInstanceOf(DXNStruct::class, $thread);
        $root = self::named($thread->pairs()[0][1]);
        self::assertIsArray($root);
        self::assertSame(0, $root['likes']);
        self::assertSame(0, $root['replies'][0]['likes']);
        self::assertSame(0, $root['replies'][0]['replies'][0]['likes']);

        $this->expectException(\RuntimeException::class);
        Dextrin::decode('%Thread{root: %{text: "a", replies: [%{text: 1}]}, value: 1}', $r);
    }

    public function testAJsonLikeValueTypeNested(): void
    {
        $r = self::registry(self::RECURSIVE);
        self::assertTrue(Schema::check(Dextrin::decode('%{"a" => [1 %{"b" => nil}]}'), $r, 'JsonValue'));
        $this->expectException(\RuntimeException::class);
        Schema::check(Dextrin::decode('%{"a" => [1 %{"b" => :x}]}'), $r, 'JsonValue');
    }

    public function testAGenericsArgumentsStandWhereItsParametersStand(): void
    {
        $r = self::registry(self::GENERICS);
        self::assertInstanceOf(DXNStruct::class, Dextrin::decode('%Feed{page: %{items: ["a"]}, pair: {1 "x"}}', $r));
        foreach (['%Feed{page: %{items: [1]}, pair: {1 "x"}}', '%Feed{page: %{items: ["a"]}, pair: {1 2}}'] as $bad) {
            try {
                Dextrin::decode($bad, $r);
                self::fail("{$bad} should be refused");
            } catch (\Throwable $err) {
                self::assertSame('schema/type-mismatch', self::categoryOf($err));
            }
        }
    }

    public function testANamedTypeMayBeOneUseOfAGeneric(): void
    {
        $r = self::registry(self::GENERICS);
        $order = Dextrin::decode('%Order{id: 1}', $r);
        self::assertTrue(Schema::check(['items' => new DXNList([$order])], $r, 'Orders'));
        $this->expectException(\RuntimeException::class);
        Schema::check(['items' => new DXNList([1])], $r, 'Orders');
    }

    public function testGenericCompileRefusals(): void
    {
        self::assertSame('schema/compile', self::compileCategory('%{P: {:generic [t] {:list-of t}}, Q: {:of P :integer :string}}'));
        self::assertSame('schema/compile', self::compileCategory('%{P: :integer, Q: {:of P :integer}}'));
        self::assertSame('schema/compile', self::compileCategory('%{P: {:generic [t] {:list-of t}}, Q: %schema{v: P}}'));
        self::assertSame('schema/compile', self::compileCategory('%{E: {:generic [p] %schema{payload: p}}}'));
        self::assertSame('schema/compile', self::compileCategory('%{L: {:generic [t] {:nilable {:of L t}}}}'));
    }

    public function testCheckNeverChangesAValueAndConformFillsIt(): void
    {
        self::assertTrue(Schema::check(['order_id' => 1], self::registry(), 'OrderPlaced'));
        self::assertSame(['order_id' => 1, 'currency' => 'EUR'], self::named(Schema::conform(['order_id' => 1], self::registry(), 'OrderPlaced')));
        try {
            Schema::check(['currency' => 'EUR'], self::registry(), 'OrderPlaced');
            self::fail('a map missing a required field should be refused');
        } catch (\Throwable $err) {
            self::assertSame('schema/type-mismatch', self::categoryOf($err));
        }
    }

    public function testAnUnknownNameIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        Schema::check(1, self::registry(), 'Nothing');
    }
}
