<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

/**
 * Hand-written `.dxn` tokenizer, directly off `dxn/DXN.md` §1.1's
 * lexical grammar. No parser-generator dependency -- the grammar is
 * small and fully normative.
 *
 * `ws = { " " | "\t" | "\n" | "\r" | "," }` is "fully insignificant,
 * everywhere" per the spec -- this lexer skips it (and `#` comments)
 * between every token rather than emitting whitespace tokens at all.
 *
 * A few sigils bind tighter than plain "insignificant whitespace
 * everywhere" would suggest:
 *   - `~D[`/`~T[`/`~U[`/`~r/` are each lexed as one compound token --
 *     no whitespace may appear between `~` and the letter that follows.
 *   - `identifier ":"` (the map-entry colon-shorthand key) is lexed as
 *     one `MAP_KEY` token when the `:` immediately follows the
 *     identifier with no whitespace between them; otherwise the `:`
 *     starts a fresh `KEYWORD` token instead (`:name` in value
 *     position), and the identifier is left as a separate token.
 *   - `keyword` (`:name` / `:"..."`) similarly requires no whitespace
 *     between `:` and what follows.
 *
 * Operates on the source split into an array of single-codepoint
 * strings (see {@see Token}'s own doc) rather than raw byte-indexed
 * PHP strings -- `\p{ID_Start}`/`\p{ID_Continue}` regex tests then
 * always run against one well-formed codepoint at a time.
 */
final class Lexer
{
    private const RESERVED = ['nil' => true, 'true' => true, 'false' => true, 'NaN' => true, 'Infinity' => true];
    private const ID_START = '/^[\p{ID_Start}_]$/u';
    private const ID_CONTINUE = '/^[\p{ID_Continue}\-?!]$/u';
    private const HEX_DIGIT = '/^[0-9a-fA-F]$/';
    private const DIGIT = '/^[0-9]$/';
    private const REGEX_FLAG = '/^[imsuxfr]$/';

    /** @var list<string> */
    private readonly array $cp;
    private readonly int $len;
    private int $i = 0;

    private function __construct(string $src)
    {
        $split = preg_split('//u', $src, -1, PREG_SPLIT_NO_EMPTY);
        if ($split === false) {
            throw new LexError('source is not valid UTF-8', 0);
        }
        $this->cp = $split;
        $this->len = count($this->cp);
    }

    /** @return list<Token> */
    public static function tokenize(string $src): array
    {
        return (new self($src))->run();
    }

    /** @return list<Token> */
    private function run(): array
    {
        $tokens = [];
        while (true) {
            $this->skipTrivia();
            if ($this->i >= $this->len) {
                break;
            }
            $tokens[] = $this->nextToken();
        }
        $tokens[] = new Token('EOF', null, $this->len, $this->len);
        return $tokens;
    }

    private function skipTrivia(): void
    {
        while (true) {
            $c = $this->cp[$this->i] ?? null;
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === ',') {
                $this->i++;
            } elseif ($c === '#') {
                while ($this->i < $this->len && $this->cp[$this->i] !== "\n") {
                    $this->i++;
                }
            } else {
                break;
            }
        }
    }

    private function nextToken(): Token
    {
        $start = $this->i;
        $c = $this->cp[$this->i];

        return match (true) {
            $c === '[' => $this->single('LBRACKET'),
            $c === ']' => $this->single('RBRACKET'),
            $c === '{' => $this->single('LBRACE'),
            $c === '}' => $this->single('RBRACE'),
            $c === '"' => $this->readString(),
            $c === '?' => $this->readChar(),
            $c === '~' => $this->readSigil(),
            $c === '@' => $this->single('AT'),
            $c === '%' => $this->single('PERCENT'),
            $c === ':' => $this->readColon(),
            $c === '=' && ($this->cp[$this->i + 1] ?? null) === '>' => $this->arrow(),
            $this->isDigit($c) || ($c === '-' && $this->isDigit($this->cp[$this->i + 1] ?? null)) => $this->readNumber(),
            $c === '-' && $this->startsWith($this->i + 1, 'Infinity') => $this->readNumber(),
            $this->isIdStart($c) => $this->readIdentifierOrKeywordLike($start),
            default => throw new LexError("unexpected character '{$c}' at offset {$start}", $start),
        };
    }

    private function single(string $type): Token
    {
        $start = $this->i;
        $this->i++;
        return new Token($type, $this->cp[$start], $start, $this->i);
    }

    private function arrow(): Token
    {
        $start = $this->i;
        $this->i += 2;
        return new Token('ARROW', '=>', $start, $this->i);
    }

    private function isDigit(?string $c): bool
    {
        return $c !== null && preg_match(self::DIGIT, $c) === 1;
    }

    private function isIdStart(string $c): bool
    {
        return preg_match(self::ID_START, $c) === 1;
    }

    private function isIdContinue(?string $c): bool
    {
        return $c !== null && preg_match(self::ID_CONTINUE, $c) === 1;
    }

    private function startsWith(int $at, string $literal): bool
    {
        $literalCp = preg_split('//u', $literal, -1, PREG_SPLIT_NO_EMPTY);
        if ($literalCp === false) {
            return false;
        }
        foreach ($literalCp as $offset => $expected) {
            if (($this->cp[$at + $offset] ?? null) !== $expected) {
                return false;
            }
        }
        return true;
    }

    private function slice(int $start, int $end): string
    {
        return implode('', array_slice($this->cp, $start, $end - $start));
    }

    /** `identifier = (letter | "_"), {ident_char}, ["/", (letter | "_"), {ident_char}]`. First char already validated present at $this->i. */
    private function readIdentifierRun(): string
    {
        $start = $this->i;
        $this->i++;
        while ($this->isIdContinue($this->cp[$this->i] ?? null)) {
            $this->i++;
        }
        if (($this->cp[$this->i] ?? null) === '/' && $this->isIdStart($this->cp[$this->i + 1] ?? '')) {
            $this->i += 2;
            while ($this->isIdContinue($this->cp[$this->i] ?? null)) {
                $this->i++;
            }
        }
        return $this->slice($start, $this->i);
    }

    private function readIdentifierOrKeywordLike(int $start): Token
    {
        $name = $this->readIdentifierRun();
        if (($this->cp[$this->i] ?? null) === ':') {
            $this->i++;
            return new Token('MAP_KEY', $name, $start, $this->i);
        }
        if (isset(self::RESERVED[$name])) {
            return $this->reservedToken($name, $start, $this->i);
        }
        return new Token('IDENTIFIER', $name, $start, $this->i);
    }

    private function reservedToken(string $name, int $start, int $end): Token
    {
        return match ($name) {
            'nil' => new Token('NIL', null, $start, $end),
            'true' => new Token('TRUE', true, $start, $end),
            'false' => new Token('FALSE', false, $start, $end),
            'NaN' => new Token('FLOAT', 'NaN', $start, $end),
            'Infinity' => new Token('FLOAT', 'Infinity', $start, $end),
            default => throw new \LogicException("unreachable reserved word: {$name}"),
        };
    }

    private function readColon(): Token
    {
        $start = $this->i;
        $this->i++;
        if (($this->cp[$this->i] ?? null) === '"') {
            $str = $this->readString();
            return new Token('KEYWORD', $str->value, $start, $this->i, isQuoted: true);
        }
        if ($this->isIdStart($this->cp[$this->i] ?? '')) {
            $name = $this->readIdentifierRun();
            return new Token('KEYWORD', $name, $start, $this->i, isQuoted: false);
        }
        throw new LexError("':' must be followed by an identifier or string", $start);
    }

    private function readNumber(): Token
    {
        $start = $this->i;
        if ($this->cp[$this->i] === '-') {
            $this->i++;
        }

        // "-Infinity" -- the only signed reserved word.
        if ($this->startsWith($this->i, 'Infinity') && !$this->isIdContinue($this->cp[$this->i + strlen('Infinity')] ?? null)) {
            $this->i += 8;
            return new Token('FLOAT', $this->slice($start, $this->i), $start, $this->i);
        }

        if (!$this->isDigit($this->cp[$this->i] ?? null)) {
            throw new LexError("invalid number literal at offset {$start}", $start);
        }
        while ($this->isDigit($this->cp[$this->i] ?? null)) {
            $this->i++;
        }

        if (($this->cp[$this->i] ?? null) === '/' && $this->isDigit($this->cp[$this->i + 1] ?? null)) {
            $this->i++;
            while ($this->isDigit($this->cp[$this->i] ?? null)) {
                $this->i++;
            }
            return new Token('RATIONAL', $this->slice($start, $this->i), $start, $this->i);
        }

        $hasFraction = false;
        if (($this->cp[$this->i] ?? null) === '.' && $this->isDigit($this->cp[$this->i + 1] ?? null)) {
            $hasFraction = true;
            $this->i++;
            while ($this->isDigit($this->cp[$this->i] ?? null)) {
                $this->i++;
            }
        }

        // decimal = int (.digits)? "M" -- checked before exponent:
        // decimal has no exponent form at all, so "M" must win over
        // "e"/"E" here.
        if (($this->cp[$this->i] ?? null) === 'M') {
            $this->i++;
            return new Token('DECIMAL', $this->slice($start, $this->i), $start, $this->i);
        }

        $isFloat = $hasFraction;
        $expChar = $this->cp[$this->i] ?? null;
        if (($expChar === 'e' || $expChar === 'E') && $this->looksLikeExponent($this->i)) {
            $isFloat = true;
            $this->i++;
            $sign = $this->cp[$this->i] ?? null;
            if ($sign === '+' || $sign === '-') {
                $this->i++;
            }
            while ($this->isDigit($this->cp[$this->i] ?? null)) {
                $this->i++;
            }
        }

        return new Token($isFloat ? 'FLOAT' : 'INTEGER', $this->slice($start, $this->i), $start, $this->i);
    }

    private function looksLikeExponent(int $at): bool
    {
        $j = $at + 1;
        $sign = $this->cp[$j] ?? null;
        if ($sign === '+' || $sign === '-') {
            $j++;
        }
        return $this->isDigit($this->cp[$j] ?? null);
    }

    private function readString(): Token
    {
        $start = $this->i;
        $this->i++; // opening quote
        while ($this->i < $this->len && $this->cp[$this->i] !== '"') {
            $this->i += ($this->cp[$this->i] === '\\') ? 2 : 1;
        }
        if (($this->cp[$this->i] ?? null) !== '"') {
            throw new LexError('unterminated string literal', $start);
        }
        $this->i++; // closing quote
        return new Token('STRING', $this->slice($start + 1, $this->i - 1), $start, $this->i);
    }

    private function readChar(): Token
    {
        $start = $this->i;
        $this->i++; // '?'
        if (($this->cp[$this->i] ?? null) === '\\') {
            $escStart = $this->i;
            $this->i++;
            if (($this->cp[$this->i] ?? null) === 'x') {
                $this->i++;
                if (($this->cp[$this->i] ?? null) !== '{') {
                    throw new LexError('invalid \\x{...} escape in char literal', $escStart);
                }
                $this->i++;
                while ($this->i < $this->len && preg_match(self::HEX_DIGIT, $this->cp[$this->i]) === 1) {
                    $this->i++;
                }
                if (($this->cp[$this->i] ?? null) !== '}') {
                    throw new LexError('invalid \\x{...} escape in char literal', $escStart);
                }
                $this->i++;
            } else {
                $this->i++;
            }
        } elseif (($this->cp[$this->i] ?? null) !== null) {
            $this->i++; // one codepoint
        }
        return new Token('CHAR', $this->slice($start + 1, $this->i), $start, $this->i);
    }

    /** Reads a delimited sigil body up to (not including) `$close`, honoring `\$close` as an escaped literal delimiter. */
    private function readUntil(string $close): string
    {
        $start = $this->i;
        while ($this->i < $this->len && $this->cp[$this->i] !== $close) {
            if ($this->cp[$this->i] === '\\' && ($this->cp[$this->i + 1] ?? null) === $close) {
                $this->i += 2;
            } else {
                $this->i++;
            }
        }
        if (($this->cp[$this->i] ?? null) !== $close) {
            throw new LexError("expected '{$close}'", $start);
        }
        $body = $this->slice($start, $this->i);
        $this->i++; // closing delimiter
        return $body;
    }

    private function readSigil(): Token
    {
        $start = $this->i;
        if ($this->startsWith($start, '~D[')) {
            $this->i += 3;
            return new Token('DATE_SIGIL', $this->readUntil(']'), $start, $this->i);
        }
        if ($this->startsWith($start, '~T[')) {
            $this->i += 3;
            return new Token('TIME_SIGIL', $this->readUntil(']'), $start, $this->i);
        }
        if ($this->startsWith($start, '~U[')) {
            $this->i += 3;
            return new Token('INSTANT_SIGIL', $this->readUntil(']'), $start, $this->i);
        }
        if ($this->startsWith($start, '~r/')) {
            $this->i += 3;
            $pattern = $this->readUntil('/');
            $flagsStart = $this->i;
            while ($this->i < $this->len && preg_match(self::REGEX_FLAG, $this->cp[$this->i]) === 1) {
                $this->i++;
            }
            return new Token('REGEX_SIGIL', ['pattern' => $pattern, 'flags' => $this->slice($flagsStart, $this->i)], $start, $this->i);
        }
        throw new LexError("unrecognized sigil at offset {$start}", $start);
    }
}
