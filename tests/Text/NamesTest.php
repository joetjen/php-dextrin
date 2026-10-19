<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNSymbol;
use PHPUnit\Framework\TestCase;

/**
 * Names (DXN.md §1.1): segments joined by `.`, with at most one `/`
 * between a namespace and a name -- wherever a name stands: a struct, a
 * symbol, a keyword, a map key. As the reference reads them (dextrin
 * 0d1c7f5).
 */
final class NamesTest extends TestCase
{
    public function testAStructsNameMayBeDottedAndNamespaced(): void
    {
        self::assertSame('Foo.Bar/Baz', Dextrin::decode('%Foo.Bar/Baz{a: 1}')->name);
        $versioned = Dextrin::decode('%Foo.Bar.Baz(2){a: 1}');
        self::assertSame(['Foo.Bar.Baz', 2], [$versioned->name, $versioned->version]);
    }

    public function testAndSoMayASymbolAKeywordAndAMapKey(): void
    {
        self::assertEquals(new DXNSymbol('a.b.c'), Dextrin::decode('a.b.c'));
        self::assertEquals(new DXNKeyword('acme.billing/invoice'), Dextrin::decode(':acme.billing/invoice'));
        self::assertSame('%{a.b:1}', Dextrin::encode(Dextrin::decode('%{a.b: 1}')));
    }

    public function testEachIsWrittenBackAsItWas(): void
    {
        foreach (['%Foo.Bar/Baz{a: 1}', 'a.b.c', ':acme.billing/invoice', '%{a.b: 1}'] as $text) {
            $written = Dextrin::encode(Dextrin::decode($text));
            self::assertSame($written, Dextrin::encode(Dextrin::decode($written)), $text);
        }
        self::assertSame('Foo.Bar/Baz', Dextrin::encode(new DXNSymbol('Foo.Bar/Baz')));
    }

    public function testANameHasAtMostOneSlashAndNoEmptySegment(): void
    {
        foreach (['Foo/Bar/Baz', 'Foo.', '.Foo', 'Foo..Bar', 'Foo./Bar', 'Foo/.Bar'] as $text) {
            try {
                Dextrin::decode($text);
            } catch (DXNError $err) {
                self::assertSame('syntax', $err->category, $text);
                continue;
            }
            self::fail("expected {$text} to be refused");
        }
    }
}
