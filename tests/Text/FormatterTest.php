<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\Compiled;
use JOetjen\Dextrin\Schema\Field;
use JOetjen\Dextrin\Text\Formatter;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    public function testScalarsAndEmptyCollectionsStaySingleLine(): void
    {
        self::assertSame('42', Formatter::pretty(42));
        self::assertSame('[]', Formatter::pretty(new DXNList([])));
        self::assertSame('@{}', Formatter::pretty(new DXNSet([])));
        self::assertSame('@array[]', Formatter::pretty([]));
        self::assertSame('@ordered %{}', Formatter::pretty(new DXNOrderedMap([])));
    }

    public function testListRendersOneItemPerLineIndented(): void
    {
        self::assertSame("[\n  1\n  2\n  3\n]", Formatter::pretty(new DXNList([1, 2, 3])));
    }

    public function testMapRendersOneEntryPerLineWithKeywordShorthand(): void
    {
        self::assertSame("%{\n  a: 1\n  b: 2\n}", Formatter::pretty(['a' => 1, 'b' => 2]));
    }

    public function testMapEntryWithNonIdentifierKeyUsesQuotedArrowForm(): void
    {
        self::assertSame("%{\n  :\"has space\" => 1\n}", Formatter::pretty(['has space' => 1]));
    }

    public function testMapEntryWithAMarkedStringKeyPrintsAsAQuotedStringNotKeywordShorthand(): void
    {
        // MapBuilder's own leading-NUL marker for a genuinely
        // string-typed key (see its doc) -- must render as a quoted
        // string, not silently become a keyword on re-encode.
        self::assertSame("%{\n  \"a\" => 1\n}", Formatter::pretty(["\0a" => 1]));
    }

    // `width: 1` breaks every nested collection, so the indentation shows.
    public function testNestedCollectionsIndentByDepth(): void
    {
        $value = new DXNList([new DXNList([1, 2])]);
        self::assertSame("[\n  [\n    1\n    2\n  ]\n]", Formatter::pretty($value, width: 1));
    }

    public function testCustomIndentSize(): void
    {
        self::assertSame("[\n    1\n]", Formatter::pretty(new DXNList([1]), 4));
    }

    public function testKeyedStructRendersLikeAMap(): void
    {
        $struct = DXNStruct::keyed('Point', [['x', 1], ['y', 2]]);
        self::assertSame("%Point{\n  x: 1\n  y: 2\n}", Formatter::pretty($struct));
    }

    public function testPositionalStructRendersLikeAList(): void
    {
        $struct = DXNStruct::positional('Point', [1, 2]);
        self::assertSame("%Point[\n  1\n  2\n]", Formatter::pretty($struct));
    }

    public function testOrderedMapPreservesOrderAndUsesTagPrefix(): void
    {
        $om = new DXNOrderedMap([[new DXNKeyword('b'), 2], [new DXNKeyword('a'), 1]]);
        self::assertSame("@ordered %{\n  b: 2\n  a: 1\n}", Formatter::pretty($om));
    }

    public function testOrderedMapWithAGenuineStringKeyPrintsAsAQuotedStringNotAKeyword(): void
    {
        // ordered-map pairs preserve their original key type directly
        // (never bare-array-collapsed the way a plain map's keys are)
        // -- a genuine string key here was never ambiguous with a
        // keyword key to begin with, so it must render as a quoted
        // string too, not the keyword shorthand.
        $om = new DXNOrderedMap([['a', 1]]);
        self::assertSame("@ordered %{\n  \"a\" => 1\n}", Formatter::pretty($om));
    }

    public function testPlainObjectRendersLikeAKeyedStruct(): void
    {
        self::assertSame("%stdClass{\n  x: 1\n}", Formatter::pretty((object) ['x' => 1]));
    }

    public function testPrettyValueDecodesBackToTheSameValue(): void
    {
        $value = new DXNList([1, new DXNList([2, 3]), ['a' => 1]]);
        $pretty = Formatter::pretty($value);
        $compact = \JOetjen\Dextrin\Text\Printer::print($value);
        self::assertEquals(
            \JOetjen\Dextrin\Text\Parser::parse($compact),
            \JOetjen\Dextrin\Text\Parser::parse($pretty),
        );
    }

    public function testRegisteredApplicationInstanceRendersUnderItsSchemaName(): void
    {
        $point = new FormatterTestPoint(1, 2);
        $compiled = new Compiled(fields: [new Field('x'), new Field('y')]);
        $registry = (new Registry())
            ->putStructModule('Point', FormatterTestPoint::class)
            ->putStructSchema('Point', $compiled);

        self::assertSame("%Point(1){\n  x: 1\n  y: 2\n}", Formatter::pretty($point, registry: $registry));
        self::assertSame("%FormatterTestPoint{\n  x: 1\n  y: 2\n}", Formatter::pretty($point));
    }

    public function testRegisteredTagEncoderInstanceStaysSingleLineNotAStruct(): void
    {
        $money = new FormatterTestMoney(500);
        $registry = (new Registry())->putTagEncoder(
            FormatterTestMoney::class,
            'my-app/money',
            static fn (FormatterTestMoney $m): int => $m->cents,
        );

        self::assertSame('@my-app/money 500', Formatter::pretty($money, registry: $registry));
    }

    // Jan, 2026-10-20: the top-level collection always breaks, one entry
    // per line; a nested one stays on one line when that line fits the
    // width (80, `$width`), its entries single-space separated.

    private static function laid(string $text, int $width = 80): string
    {
        return Formatter::pretty(\JOetjen\Dextrin\Dextrin::decode($text), width: $width);
    }

    public function testTheTopLevelBreaksHoweverShortItIs(): void
    {
        self::assertSame("[\n  1\n  2\n]", self::laid('[1 2]'));
        self::assertSame("%{\n  a: 1\n}", self::laid('%{a: 1}'));
    }

    public function testANestedCollectionThatFitsIsWrittenOnOneLine(): void
    {
        self::assertSame("[\n  [1 2]\n  {3 \"a\"}\n]", self::laid('[[1 2] {3 "a"}]'));
        self::assertSame("%{\n  a: %{b: @array[1]}\n}", self::laid('%{a: %{b: @array[1]}}'));
        self::assertSame("[\n  @ordered %{y: 2 x: 1}\n]", self::laid('[@ordered %{y: 2 x: 1}]'));
        self::assertSame("[\n  %P{q: 1 r: \"s\"}\n]", self::laid('[%P{q: 1 r: "s"}]'));
        self::assertSame("[\n  %P[1 2]\n]", self::laid('[%P[1 2]]'));
    }

    public function testTheLineItFitsOnCountsItsIndentAndItsKey(): void
    {
        // "  key: [1 2 3]" is 14 columns.
        self::assertSame("%{\n  key: [1 2 3]\n}", self::laid('%{key: [1 2 3]}', 14));
        self::assertSame("%{\n  key: [\n    1\n    2\n    3\n  ]\n}", self::laid('%{key: [1 2 3]}', 13));
    }

    public function testOneThatDoesNotFitBreaksAndWhatItHoldsIsLaidOutAgain(): void
    {
        $long = str_repeat('x', 40);
        self::assertSame(
            "[\n  [\n    [1 2]\n    \"{$long}\"\n    \"{$long}\"\n  ]\n]",
            self::laid("[[[1 2] \"{$long}\" \"{$long}\"]]"),
        );
    }

    public function testTheWidthIs80UnlessGiven(): void
    {
        // '  ["' and '"]' leave 74 columns of 80 for the string.
        $fits = str_repeat('x', 74);
        self::assertSame("[\n  [\"{$fits}\"]\n]", self::laid("[[\"{$fits}\"]]"));
        $over = str_repeat('x', 75);
        self::assertSame("[\n  [\n    \"{$over}\"\n  ]\n]", self::laid("[[\"{$over}\"]]"));
    }
}

final class FormatterTestMoney
{
    public function __construct(public int $cents)
    {
    }
}

final class FormatterTestPoint
{
    public function __construct(
        public int $x,
        public int $y,
    ) {
    }
}
