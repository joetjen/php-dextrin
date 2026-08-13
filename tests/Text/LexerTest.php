<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Text;

use JOetjen\Dextrin\Text\LexError;
use JOetjen\Dextrin\Text\Lexer;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    /** @return list<string> */
    private function types(string $src): array
    {
        return array_map(static fn ($t) => $t->type, Lexer::tokenize($src));
    }

    public function testTokenizesStructuralBrackets(): void
    {
        self::assertSame(['LBRACKET', 'RBRACKET', 'EOF'], $this->types('[]'));
        self::assertSame(['LBRACE', 'RBRACE', 'EOF'], $this->types('{}'));
    }

    public function testTokenizesReservedWords(): void
    {
        self::assertSame(['NIL', 'EOF'], $this->types('nil'));
        self::assertSame(['TRUE', 'EOF'], $this->types('true'));
        self::assertSame(['FALSE', 'EOF'], $this->types('false'));
    }

    public function testSkipsWhitespaceCommasAndComments(): void
    {
        self::assertSame(['TRUE', 'FALSE', 'EOF'], $this->types("true, false"));
        self::assertSame(['TRUE', 'EOF'], $this->types("true # a comment\n"));
    }

    public function testTokenizesIntegerFloatDecimalRational(): void
    {
        $tokens = Lexer::tokenize('42 -17 3.14 19.99M 22/7');
        self::assertSame(['INTEGER', 'INTEGER', 'FLOAT', 'DECIMAL', 'RATIONAL', 'EOF'], array_map(fn ($t) => $t->type, $tokens));
        self::assertSame('42', $tokens[0]->value);
        self::assertSame('-17', $tokens[1]->value);
        self::assertSame('3.14', $tokens[2]->value);
        self::assertSame('19.99M', $tokens[3]->value);
        self::assertSame('22/7', $tokens[4]->value);
    }

    public function testDecimalWinsOverExponentForTrailingM(): void
    {
        // "M" must win over "e"/"E" -- decimal has no exponent form.
        $tokens = Lexer::tokenize('19.99M');
        self::assertSame('DECIMAL', $tokens[0]->type);
        self::assertSame('19.99M', $tokens[0]->value);
    }

    public function testTokenizesFloatExponent(): void
    {
        $tokens = Lexer::tokenize('1.5e10');
        self::assertSame('FLOAT', $tokens[0]->type);
        self::assertSame('1.5e10', $tokens[0]->value);
    }

    public function testTokenizesNanAndInfinity(): void
    {
        foreach (['NaN', 'Infinity', '-Infinity'] as $s) {
            $tokens = Lexer::tokenize($s);
            self::assertSame('FLOAT', $tokens[0]->type);
            self::assertSame($s, $tokens[0]->value);
        }
    }

    public function testTokenizesStringWithEscapes(): void
    {
        $tokens = Lexer::tokenize('"hello\\nworld"');
        self::assertSame('STRING', $tokens[0]->type);
        self::assertSame('hello\\nworld', $tokens[0]->value);
    }

    public function testUnterminatedStringThrows(): void
    {
        $this->expectException(LexError::class);
        Lexer::tokenize('"unterminated');
    }

    public function testTokenizesKeywordBareAndQuoted(): void
    {
        $tokens = Lexer::tokenize(':foo :"bar baz"');
        self::assertSame('KEYWORD', $tokens[0]->type);
        self::assertSame('foo', $tokens[0]->value);
        self::assertFalse($tokens[0]->isQuoted);
        self::assertSame('KEYWORD', $tokens[1]->type);
        self::assertSame('bar baz', $tokens[1]->value);
        self::assertTrue($tokens[1]->isQuoted);
    }

    public function testColonWithoutIdentifierOrStringThrows(): void
    {
        $this->expectException(LexError::class);
        Lexer::tokenize(': 5');
    }

    public function testTokenizesMapKeyShorthandOnlyWithNoWhitespaceBeforeColon(): void
    {
        self::assertSame(['MAP_KEY', 'INTEGER', 'EOF'], $this->types('foo:1'));
        // A space before ':' means the colon starts a fresh keyword instead.
        self::assertSame(['IDENTIFIER', 'KEYWORD', 'EOF'], $this->types('foo :bar'));
    }

    public function testTokenizesIdentifierWithNamespace(): void
    {
        $tokens = Lexer::tokenize('Address/Address');
        self::assertSame('IDENTIFIER', $tokens[0]->type);
        self::assertSame('Address/Address', $tokens[0]->value);
    }

    public function testTokenizesUnicodeIdentifier(): void
    {
        $tokens = Lexer::tokenize('café');
        self::assertSame('IDENTIFIER', $tokens[0]->type);
        self::assertSame('café', $tokens[0]->value);
    }

    public function testTokenizesCharLiteral(): void
    {
        self::assertSame('a', Lexer::tokenize('?a')[0]->value);
        self::assertSame('s', Lexer::tokenize('?s')[0]->value);
        self::assertSame('\\n', Lexer::tokenize('?\\n')[0]->value);
    }

    public function testTokenizesSigils(): void
    {
        $date = Lexer::tokenize('~D[2024-06-01]');
        self::assertSame('DATE_SIGIL', $date[0]->type);
        self::assertSame('2024-06-01', $date[0]->value);

        $time = Lexer::tokenize('~T[13:45:30]');
        self::assertSame('TIME_SIGIL', $time[0]->type);

        $instant = Lexer::tokenize('~U[2024-06-01 13:45:30Z]');
        self::assertSame('INSTANT_SIGIL', $instant[0]->type);

        $regex = Lexer::tokenize('~r/foo/im');
        self::assertSame('REGEX_SIGIL', $regex[0]->type);
        self::assertSame(['pattern' => 'foo', 'flags' => 'im'], $regex[0]->value);
    }

    public function testTokenizesAtAndPercentAndArrow(): void
    {
        self::assertSame(['AT', 'IDENTIFIER', 'EOF'], $this->types('@foo'));
        self::assertSame(['PERCENT', 'LBRACE', 'RBRACE', 'EOF'], $this->types('%{}'));
        self::assertSame(['ARROW', 'EOF'], $this->types('=>'));
    }

    public function testUnexpectedCharacterThrows(): void
    {
        $this->expectException(LexError::class);
        Lexer::tokenize('^');
    }

    public function testOffsetsAreCodepointIndicesNotByteOffsets(): void
    {
        // "café" is 4 codepoints but 5 bytes in UTF-8 (é is 2 bytes) --
        // the following identifier must start at codepoint index 5
        // (4 for "café" + 1 for the space), not byte offset 6.
        $tokens = Lexer::tokenize('café bar');
        self::assertSame(4, $tokens[0]->end);
        self::assertSame(5, $tokens[1]->start);
    }
}
