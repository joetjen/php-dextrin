<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Value\DXNSymbol;
use PHPUnit\Framework\TestCase;

/**
 * A symbol is an identifier (DXN.md §1.1), so text can only write one
 * whose name is an identifier. Any other name has no text form, and
 * writing it verbatim answered text that read back as something else, or
 * as nothing; it is refused, as `encode/unsupported`. Binary keeps any
 * name.
 */
final class SymbolNameTest extends TestCase
{
    public function testASymbolNamedByAnIdentifierIsWrittenAsItIs(): void
    {
        self::assertSame('ok', Dextrin::encode(new DXNSymbol('ok')));
        self::assertSame('my-app/money', Dextrin::encode(new DXNSymbol('my-app/money')));
    }

    public function testAnyOtherNameHasNoTextFormAndIsRefused(): void
    {
        foreach (['', '{', 'a b', '1x', 'nil', 'x:'] as $name) {
            foreach ([
                static fn () => Dextrin::encode(new DXNSymbol($name)),
                static fn () => Dextrin::encode(new DXNSymbol($name), pretty: true),
                static fn () => Canonical::print(new DXNSymbol($name)),
            ] as $writing) {
                try {
                    $writing();
                    self::fail("expected a refusal for {$name}");
                } catch (DXNError $err) {
                    self::assertSame('encode/unsupported', $err->category, $name);
                }
            }
        }
    }

    public function testBinaryKeepsAnyName(): void
    {
        self::assertEquals(new DXNSymbol('a b'), Dextrin::decodeBinary(Dextrin::encodeBinary(new DXNSymbol('a b'))));
    }
}
