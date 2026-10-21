<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Form;
use JOetjen\Dextrin\Format;
use PHPUnit\Framework\TestCase;

/**
 * `Format` (DXN.md §6), as `Dextrin.Format`: the media types and
 * extensions of the three forms, which form a media type names, and a
 * cheap test of whether a body is worth decoding as a form -- the text
 * rule as Jan set it on 2026-10-20.
 */
final class FormatTest extends TestCase
{
    public function testEachFormHasAMediaTypeAndAnExtension(): void
    {
        self::assertSame('application/vnd.dxn', Format::mediaType(Form::Text));
        self::assertSame('application/vnd.dxnb', Format::mediaType(Form::Binary));
        self::assertSame('application/vnd.dxns', Format::mediaType(Form::Schema));
        self::assertSame('.dxn', Format::extension(Form::Text));
        self::assertSame('.dxnb', Format::extension(Form::Binary));
        self::assertSame('.dxns', Format::extension(Form::Schema));
    }

    public function testAMediaTypeNamesItsFormAPlusDxnSuffixTextInAnyCase(): void
    {
        self::assertSame(Form::Text, Format::form('application/vnd.dxn'));
        self::assertSame(Form::Binary, Format::form('application/vnd.dxnb'));
        self::assertSame(Form::Schema, Format::form('application/vnd.dxns'));
        self::assertSame(Form::Text, Format::form('application/vnd.acme.event+dxn'));
        self::assertSame(Form::Text, Format::form('Application/VND.DXN'));
    }

    public function testNothingElseDoesNoPlusDxnbSuffixNoParameters(): void
    {
        self::assertNull(Format::form('application/json'));
        self::assertNull(Format::form('application/vnd.acme.event+dxnb'));
        self::assertNull(Format::form('application/vnd.dxn; charset=utf-8'));
        self::assertNull(Format::form(''));
    }

    public function testTextAndSchemasStartWithAPercent(): void
    {
        foreach (['%{a: 1}', '%Point{x: 1}', '%ns/Name{x: 1}', '%{P: %schema{x: :integer}}', '%(1)'] as $body) {
            self::assertTrue(Format::identifies($body, Form::Text), $body);
            self::assertTrue(Format::identifies($body, Form::Schema), $body);
        }
    }

    public function testAfterWhitespaceCommasCommentsAndAHeader(): void
    {
        self::assertTrue(Format::identifies("\n  , # a comment\n# another\n@dxn \"1.0\"  # the version\n\n%{a: 1}", Form::Text));
    }

    public function testAListOrAScalarIsLeftUnrecognisedOnPurpose(): void
    {
        foreach (['[1 2]', '"a"', '1', ':a', '{1 2}', '@array[1]', 'true', '', '# only a comment', '{"a": 1}'] as $body) {
            self::assertFalse(Format::identifies($body, Form::Text), $body);
        }
    }

    public function testBinaryIsTheEnvelopesMagicAndAVersionByte(): void
    {
        self::assertTrue(Format::identifies(Dextrin::encodeBinary(['a' => 1]), Form::Binary));
        self::assertTrue(Format::identifies("DX\x01", Form::Binary));
        self::assertFalse(Format::identifies('DX', Form::Binary));
        self::assertFalse(Format::identifies('%{a: 1}', Form::Binary));
        self::assertFalse(Format::identifies('', Form::Binary));
    }
}
