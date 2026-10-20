<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Document;
use JOetjen\Dextrin\Document\DocumentError;
use JOetjen\Dextrin\Tests\Support\RandomValueGenerator;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\Equality;
use PHPUnit\Framework\TestCase;

/**
 * Phase C of `dextrin`'s design/implementation-plan.md, mirroring
 * `Dextrin.Document`: a document keeps every comment, blank line and
 * comma, renders back byte for byte when untouched, and its value is
 * decode's. Edits change exactly what they name; update makes a new
 * value by them; format lays a document out and keeps its comments.
 */
final class DocumentTest extends TestCase
{
    private const ITERATIONS = 200;

    private const DEPS = "%{\n  deps: [\n    # Pinned until upstream fixes #431.\n    \"left-pad\"  # temporary\n    \"right-pad\"\n  ]\n}\n";

    /** Every line of `$text` with a numbered comment after it. */
    private static function commented(string $text): string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            $lines[$i] = "{$line}  # c{$i}";
        }
        return implode("\n", $lines);
    }

    /** @return list<string> */
    private static function commentsOf(string $text): array
    {
        preg_match_all('/# c\d+/', $text, $matches);
        $found = $matches[0];
        sort($found);
        return $found;
    }

    /** `$value` pretty-printed, or null when it has no text form. */
    private static function prettyOrNull(mixed $value): ?string
    {
        try {
            return Dextrin::encode($value, pretty: true);
        } catch (DXNError) {
            return null;
        }
    }

    private static function rng(): \Random\Randomizer
    {
        return new \Random\Randomizer(new \Random\Engine\Mt19937(20261019));
    }

    public function testAnUntouchedDocumentRendersBackByteForByteAndItsValueIsDecodes(): void
    {
        $text = "# top\n\n%{\n  name: \"acme\"  # the name\n  version:  # between\n    \"1.0.0\"\n  deps: [\n    \"a\", # temporary\n    # nothing follows\n  ]\n}\n# the end\n";
        $doc = Document::parse($text);
        self::assertSame($text, $doc->render());
        self::assertTrue(Equality::dxnEquals(Dextrin::decode($text), $doc->toValue()));
    }

    public function testADocumentThatIsNotOneIsRefusedTheWayDecodeRefusesIt(): void
    {
        try {
            Document::parse('[1 2');
            self::fail('expected a refusal');
        } catch (DXNError $err) {
            self::assertSame('syntax', $err->category);
        }
    }

    public function testAReplacedValueKeepsTheCommentBesideIt(): void
    {
        $doc = Document::parse("%{\n  version: \"1.0.0\" # bumped for the fix\n}\n");
        self::assertSame("%{\n  version: \"2.0.0\" # bumped for the fix\n}\n", $doc->replace(['version'], '2.0.0')->render());
    }

    public function testADeletedEntryTakesItsAboveBlockAndItsTrailingBlock(): void
    {
        self::assertSame(
            "%{\n  deps: [\n    \"right-pad\"\n  ]\n}\n",
            Document::parse(self::DEPS)->delete([new DXNKeyword('deps'), 0])->render(),
        );
    }

    public function testAPathThatLeadsNowhereIsRefused(): void
    {
        $this->expectException(DocumentError::class);
        Document::parse('%{a: 1}')->delete(['nope']);
    }

    public function testAnUpdateThatCouldLoseACommentByGuessingIsRefused(): void
    {
        try {
            Document::parse(self::DEPS)->update(Dextrin::decode('%{deps: ["other-pad" "right-pad"]}'));
            self::fail('expected a refusal');
        } catch (DocumentError $err) {
            self::assertSame('document/ambiguous', $err->category());
        }
    }

    public function testAnyDocumentRendersBackByteForByteAndItsValueIsDecodes(): void
    {
        $rng = self::rng();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $pretty = self::prettyOrNull(RandomValueGenerator::value($rng, 0));
            if ($pretty === null) {
                continue;
            }
            $text = self::commented($pretty);
            $doc = Document::parse($text);
            self::assertSame($text, $doc->render());
            self::assertTrue(Equality::dxnEquals(Dextrin::decode($text), $doc->toValue()), $text);
        }
    }

    public function testReplaceDeleteAndInsertEachMakeTheValueTheySay(): void
    {
        $rng = self::rng();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $items = RandomValueGenerator::items($rng, 1);
            $value = RandomValueGenerator::value($rng, 1);
            $pretty = self::prettyOrNull(new DXNList($items));
            if ($items === [] || $pretty === null || self::prettyOrNull($value) === null) {
                continue;
            }
            $doc = Document::parse(self::commented($pretty));
            $original = $doc->toValue();
            self::assertInstanceOf(DXNList::class, $original);
            $read = Dextrin::decode(Dextrin::encode($value));
            $at = $rng->getInt(0, count($original->items) - 1);

            $replaced = $original->items;
            $replaced[$at] = $read;
            self::assertTrue(Equality::dxnEquals(new DXNList($replaced), $doc->replace([$at], $value)->toValue()));

            $removed = $original->items;
            array_splice($removed, $at, 1);
            self::assertTrue(Equality::dxnEquals(new DXNList(array_values($removed)), $doc->delete([$at])->toValue()));

            $added = $original->items;
            array_splice($added, $at, 0, [$read]);
            self::assertTrue(Equality::dxnEquals(new DXNList($added), $doc->insert([], $at, $value)->toValue()));
        }
    }

    public function testTheUpdatedDocumentHoldsTheNewValue(): void
    {
        $rng = self::rng();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $old = self::prettyOrNull(RandomValueGenerator::value($rng, 0));
            $new = RandomValueGenerator::value($rng, 0);
            if ($old === null || self::prettyOrNull($new) === null) {
                continue;
            }
            $doc = Document::parse(self::commented($old));
            self::assertSame([], $doc->update($doc->toValue())[1]);
            $read = Dextrin::decode(Dextrin::encode($new));
            self::assertTrue(Equality::dxnEquals($read, $doc->update($read, 'guess')[0]->toValue()));
        }
    }

    // Jan, 2026-10-20: a nested collection that fits goes on one line, as
    // the pretty printer writes it -- unless it holds a comment or a blank
    // line, which keep it over lines.

    public function testFormatWritesANestedCollectionThatFitsOnOneLine(): void
    {
        self::assertSame(
            "%{\n  deps: [\"a\" \"b\"]\n  at: %{x: 1 y: 2}\n}",
            Document::parse('%{deps: ["a" "b"], at: %{x: 1, y: 2}}')->format(),
        );
    }

    public function testFormatBreaksTheTopLevelHoweverShortItIs(): void
    {
        self::assertSame("[\n  1\n]", Document::parse('[1]')->format());
    }

    public function testFormatKeepsOneHoldingACommentOverLines(): void
    {
        self::assertSame("%{\n  deps: [\n    \"a\"  # why\n  ]\n}", Document::parse("%{deps: [\"a\" # why\n]}")->format());
    }

    public function testFormatKeepsOneWithABlankLineOverLines(): void
    {
        self::assertSame(
            "%{\n  deps: [\n    \"a\"\n\n    \"b\"\n  ]\n}",
            Document::parse("%{deps: [\"a\"\n\n\"b\"]}")->format(),
        );
    }

    public function testFormatCountsTheIndentAndTheKeyInTheWidth(): void
    {
        $doc = Document::parse('%{key: [1 2 3]}');
        self::assertSame("%{\n  key: [1 2 3]\n}", $doc->format(width: 14));
        self::assertSame("%{\n  key: [\n    1\n    2\n    3\n  ]\n}", $doc->format(width: 13));
    }

    public function testFormatLaysADocumentOutAndKeepsItsComments(): void
    {
        $text = "# top\n\n%{name: \"acme\", # the name\n deps: [\"a\" # temporary\n# still about a\n \"b\"\n\n# pinned\n \"c\"\n# nothing follows\n], empty: [], v: 1.50M}\n# the end\n";
        self::assertSame(
            "# top\n\n%{\n  name: \"acme\"  # the name\n  deps: [\n    \"a\"  # temporary\n         # still about a\n    \"b\"\n\n    # pinned\n    \"c\"\n    # nothing follows\n  ]\n  empty: []\n  v: 1.50M\n}\n# the end\n",
            Document::parse($text)->format(),
        );
    }

    // The header is `@dxn` and its version, one space between them,
    // however they were spaced.
    public function testTheHeaderKeepsOneSpaceBetweenItsTagAndItsVersion(): void
    {
        self::assertSame("@dxn \"1.0\"\n[\n  1\n]", Document::parse("@dxn   \"1.0\"\n[1]")->format());
        self::assertSame("@dxn \"1.0\"\n[\n  1\n]", Document::parse("@dxn\"1.0\"\n[1]")->format());
    }

    // A formatter run on save must not take a file's last newline away;
    // one the source did not end with, it does not add.
    public function testADocumentEndsWithANewlineExactlyWhenTheSourceDid(): void
    {
        self::assertSame("%{\n  a: 1\n}\n", Document::parse("%{a: 1}\n")->format());
        self::assertSame("%{\n  a: 1\n}", Document::parse("%{a: 1}")->format());
    }

    public function testADocumentWithNoCommentsComesOutAsThePrettyPrinterWritesItsValue(): void
    {
        $rng = self::rng();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $pretty = self::prettyOrNull(RandomValueGenerator::value($rng, 0));
            if ($pretty !== null) {
                self::assertSame($pretty, Document::parse($pretty)->format());
            }
        }
    }

    public function testACommentedOneKeepsItsValueAndEveryCommentAndFormatsTheSameAgain(): void
    {
        $rng = self::rng();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $pretty = self::prettyOrNull(RandomValueGenerator::value($rng, 0));
            if ($pretty === null) {
                continue;
            }
            $text = self::commented($pretty);
            $doc = Document::parse($text);
            $once = $doc->format();
            $again = Document::parse($once);
            self::assertTrue(Equality::dxnEquals($doc->toValue(), $again->toValue()), $text);
            self::assertSame(self::commentsOf($text), self::commentsOf($once));
            self::assertSame($once, $again->format());
        }
    }
}
