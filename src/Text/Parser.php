<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Text;

use JOetjen\Dextrin\KeyNotAllowed;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\MapKey;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\Validated;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Schema\Reader;
use JOetjen\Dextrin\Schema\SchemaCompileError;
use JOetjen\Dextrin\Schema\SchemaValidationError;
use JOetjen\Dextrin\Value\DXNBits;
use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUri;
use JOetjen\Dextrin\Value\DXNUuid;

/**
 * Hand-written recursive-descent `.dxn` parser -- the reverse of
 * {@see Printer}, built directly on {@see Lexer}'s token stream.
 *
 * A `struct` decodes against a compiled schema when `$registry` has
 * one for that name ({@see Reader::read()}, which picks the schema by
 * the struct's version, same as the binary decoder); with no registry,
 * or no schema registered for that name, it decodes to an opaque {@see DXNStruct}. Same story for a
 * non-built-in tag against the registry's own tag table -- falls back
 * to {@see DXNCustomTag} -- never a hard failure, matching
 * `dxn/DXN.md` §1.4's "opaque tagged value, not an error" contract.
 */
final class Parser
{
    private const BUILT_IN_TAGS = [
        'ordered' => true, 'sorted-set' => true, 'array' => true, 'uuid' => true,
        'uri' => true, 'bytes' => true, 'bits' => true, 'date' => true, 'time' => true,
        'datetime' => true, 'local-datetime' => true, 'duration' => true, 'regex' => true,
    ];

    /** A regex's flag letters, as a header may give them (DXN.md §1.2). */
    private const REGEX_FLAGS = ['i', 'm', 's', 'u', 'x', 'f', 'r'];

    /** @var list<Token> */
    private readonly array $tokens;
    private int $pos = 0;
    private ?Registry $registry;

    /**
     * The syntax tree being recorded, when one was asked for
     * ({@see self::parseTree()}): a stack of open nodes, the document at
     * its bottom.
     *
     * @var list<SyntaxNode>|null
     */
    private ?array $recording;

    /** @param list<Token> $tokens */
    private function __construct(array $tokens, ?Registry $registry, bool $record = false)
    {
        $this->tokens = $tokens;
        $this->registry = $registry;
        $this->recording = $record ? [new SyntaxNode('document', 0)] : null;
    }

    /**
     * `$text` parsed the way {@see self::parse()} parses it -- the same
     * parser, the same errors -- with the syntax tree beside the value.
     * What {@see \JOetjen\Dextrin\Document::parse()} reads with.
     *
     * @return array{0: mixed, 1: SyntaxNode, 2: list<Token>}
     */
    public static function parseTree(string $text, ?Registry $registry = null): array
    {
        $tokens = Lexer::tokenize($text);
        $parser = new self($tokens, $registry, true);
        $value = Validated::strip($parser->parseDocument());
        /** @var list<SyntaxNode> $recording */
        $recording = $parser->recording;
        return [$value, $recording[0], $tokens];
    }

    /** Opens a node of `$kind` at the next token -- or at token `$first` -- when a tree is being recorded. */
    private function begin(string $kind, ?int $first = null): void
    {
        if ($this->recording !== null) {
            $this->recording[] = new SyntaxNode($kind, $first ?? $this->pos);
        }
    }

    /** Closes the innermost open node at the last token consumed, under its parent. */
    private function end(): void
    {
        if ($this->recording === null) {
            return;
        }
        $node = array_pop($this->recording);
        if ($node === null || $this->recording === []) {
            throw new \LogicException('a node was closed that was never opened');
        }
        $node->last = $this->pos - 1;
        $this->recording[count($this->recording) - 1]->children[] = $node;
    }

    /** Notes on the innermost open node that the last token consumed is its `$field`. */
    private function mark(string $field): void
    {
        if ($this->recording === null) {
            return;
        }
        $node = $this->recording[count($this->recording) - 1];
        match ($field) {
            'open' => $node->open = $this->pos - 1,
            'key' => $node->key = $this->pos - 1,
            'arrow' => $node->arrow = $this->pos - 1,
            'name' => $node->name = $this->pos - 1,
            default => throw new \LogicException("a node has no field {$field}"),
        };
    }

    /**
     * Makes the value just recorded, starting at token `$first`, the key
     * of a new entry: a positional struct's item turns out to be an extra
     * field's key once `=>` follows it.
     */
    private function rewrapAsEntry(int $first): void
    {
        if ($this->recording === null) {
            return;
        }
        $parent = $this->recording[count($this->recording) - 1];
        $key = array_pop($parent->children);
        $entry = new SyntaxNode('entry', $first);
        if ($key !== null) {
            $entry->children[] = $key;
        }
        $this->recording[] = $entry;
    }

    public static function parse(string $text, ?Registry $registry = null): mixed
    {
        $parser = new self(Lexer::tokenize($text), $registry);
        // The true top of decoding -- a schema-registered struct
        // decoded anywhere in the document (including the document's
        // own top-level value) carries a `Validated` wrapper until
        // whatever consumes it strips it; nothing else does that for
        // the top-level result, so `parse()` itself must.
        return Validated::strip($parser->parseDocument());
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos];
    }

    /**
     * Reads `$this->pos`, mutable parser state -- PHPStan otherwise
     * assumes a "pure" method called twice with the same argument
     * returns the same result both times, which doesn't hold here
     * once something between the two calls (typically `next()`)
     * advances the cursor.
     * @phpstan-impure
     */
    private function at(string $type): bool
    {
        return $this->peek()->type === $type;
    }

    private function next(string $type): Token
    {
        $tok = $this->peek();
        if ($tok->type !== $type) {
            throw new ParseError("expected {$type}, got {$tok->type}", $tok->start);
        }
        $this->pos++;
        return $tok;
    }

    /** Top-level entry: optional header, then exactly one value. */
    private function parseDocument(): mixed
    {
        $this->tryConsumeHeader();
        $value = $this->parseValue();
        $this->next('EOF');
        if ($this->recording !== null) {
            $this->recording[0]->last = $this->pos - 1;
        }
        return $value;
    }

    private function tryConsumeHeader(): void
    {
        $save = $this->pos;
        $second = $this->tokens[$this->pos + 1] ?? null;
        $third = $this->tokens[$this->pos + 2] ?? null;
        if ($this->at('AT') && $second?->type === 'IDENTIFIER' && $second->value === 'dxn' && $third?->type === 'STRING') {
            $this->begin('header');
            $this->pos += 3;
            $this->end();
        } else {
            $this->pos = $save;
        }
    }

    /** `value = {skip}, value_body` -- `skip` here is only ever a discard; comments never reach the token stream. */
    private function parseValue(): mixed
    {
        $this->begin('value');
        $this->skipDiscards();
        $this->begin('body');
        $value = $this->parseValueBody();
        $this->end();
        $this->end();
        return $value;
    }

    private function isDiscard(): bool
    {
        $next = $this->tokens[$this->pos + 1] ?? null;
        return $next?->type === 'IDENTIFIER' && $next->value === '_';
    }

    private function skipDiscards(): void
    {
        while ($this->at('AT') && $this->isDiscard()) {
            $this->begin('discard');
            $this->pos += 2; // AT, IDENTIFIER "_"
            $this->parseValue(); // parse and throw away
            $this->end();
        }
    }

    /**
     * One value, read off the tokens.
     *
     * **A value written well that is not one** -- an impossible date, a
     * uuid that is not one, an unknown escape -- is text that does not
     * parse: the `\InvalidArgumentException` the value's own constructor
     * throws becomes a `syntax` error at the token the value began with,
     * as the reference answers. One that names its own category (a map
     * key not allowed) and any other error pass through as they are.
     */
    private function parseValueBody(): mixed
    {
        $tok = $this->peek();
        try {
            return $this->parseValueToken($tok);
        } catch (\InvalidArgumentException $err) {
            if ($err instanceof HasCategory) {
                throw $err;
            }
            throw new ParseError($err->getMessage(), $tok->start);
        }
    }

    /** The value `$tok` begins. */
    private function parseValueToken(Token $tok): mixed
    {
        return match ($tok->type) {
            'NIL' => $this->advanceAnd(null),
            'TRUE' => $this->advanceAnd(true),
            'FALSE' => $this->advanceAnd(false),
            'INTEGER' => DXNInteger::represent($this->advance()->value),
            'FLOAT' => $this->parseFloatToken($this->advance()->value),
            'DECIMAL' => DXNDecimal::fromString(substr($this->advance()->value, 0, -1)),
            'RATIONAL' => $this->parseRationalToken($this->advance()->value),
            'STRING' => Escapes::decodeString($this->advance()->value),
            'CHAR' => $this->parseCharToken(),
            'KEYWORD' => $this->parseKeywordToken(),
            'IDENTIFIER' => new DXNSymbol($this->advance()->value),
            'LBRACKET' => $this->parseList(),
            'LBRACE' => $this->parseTuple(),
            'PERCENT' => $this->parsePercent(),
            'AT' => $this->parseAt(),
            default => throw new ParseError("unexpected token {$tok->type}", $tok->start),
        };
    }

    private function advance(): Token
    {
        return $this->tokens[$this->pos++];
    }

    /** Advances past the current token (already matched by the caller) and returns `$value` -- for literals with no token property worth extracting. */
    private function advanceAnd(mixed $value): mixed
    {
        $this->pos++;
        return $value;
    }

    private function parseFloatToken(string $text): float
    {
        return match ($text) {
            'NaN' => NAN,
            'Infinity' => INF,
            '-Infinity' => -INF,
            default => (float) $text,
        };
    }

    private function parseRationalToken(string $text): DXNRational
    {
        $slash = strpos($text, '/');
        if ($slash === false) {
            throw new \LogicException("unreachable: rational token without '/': {$text}");
        }
        return new DXNRational(substr($text, 0, $slash), substr($text, $slash + 1));
    }

    private function parseCharToken(): DXNChar
    {
        $tok = $this->advance();
        $body = $tok->value;
        $offset = $tok->start;
        if ($body === 's') {
            return new DXNChar(0x20);
        }
        if (str_starts_with($body, '\\')) {
            [$text, $next] = Escapes::decodeEscape($body, 1);
            if ($next !== strlen($body)) {
                throw new ParseError('trailing characters after char escape', $offset);
            }
            $codepoints = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
            if ($codepoints === false || count($codepoints) !== 1) {
                throw new ParseError('char escape must decode to exactly one codepoint', $offset);
            }
            return new DXNChar(mb_ord($codepoints[0], 'UTF-8'));
        }
        $codepoints = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY);
        if ($codepoints === false || count($codepoints) !== 1) {
            throw new ParseError("invalid char literal '?{$body}'", $offset);
        }
        if ($codepoints[0] === ' ') {
            throw new ParseError("a literal space is not a valid char body; use '?s'", $offset);
        }
        return new DXNChar(mb_ord($codepoints[0], 'UTF-8'));
    }

    private function parseKeywordToken(): DXNKeyword
    {
        $tok = $this->advance();
        $name = $tok->isQuoted ? Escapes::decodeString($tok->value) : $tok->value;
        return new DXNKeyword($name);
    }

    /**
     * `@regex "pattern"`, its flags the header's letters, each once
     * (DXN.md §1.2). A pattern PCRE cannot compile is no regex: `syntax`,
     * as the reference refuses one Erlang's `:re` cannot. `f` (firstline)
     * has no PHP modifier, so the pattern is compiled without it.
     *
     * @throws \InvalidArgumentException for a flag given twice, an unknown one, or a pattern that is no regex.
     */
    private function parseRegexTag(string $flags, int $offset): DXNRegex
    {
        $letters = str_split($flags);
        if ($flags !== '' && (array_diff($letters, self::REGEX_FLAGS) !== [] || count(array_unique($letters)) !== count($letters))) {
            throw new \InvalidArgumentException("@regex({$flags}): the flags are i m s u x f r, each once");
        }
        $pattern = $this->parseStringValue('regex', $offset);
        $set = [];
        foreach (self::REGEX_FLAGS as $flag) {
            $set[$flag] = str_contains($flags, $flag);
        }
        $probe = new DXNRegex($pattern, ['f' => false] + $set);
        if (@preg_match($probe->toPcrePattern(), '') === false) {
            throw new \InvalidArgumentException("invalid regex \"{$pattern}\": " . preg_last_error_msg());
        }
        return new DXNRegex($pattern, $set);
    }

    private function parseList(): DXNList
    {
        return new DXNList($this->parseBracketedItems('list', 'LBRACKET', 'RBRACKET'));
    }

    private function parseTuple(): DXNTuple
    {
        return new DXNTuple($this->parseBracketedItems('tuple', 'LBRACE', 'RBRACE'));
    }

    /** @param int $at the index of the set's `@`, already consumed. */
    private function parseSet(int $at): DXNSet
    {
        return new DXNSet($this->parseBracketedItems('set', 'LBRACE', 'RBRACE', $at));
    }

    /** @return list<mixed> */
    private function parseBracketedItems(string $kind, string $open, string $close, ?int $first = null): array
    {
        $this->begin($kind, $first);
        $this->next($open);
        $this->mark('open');
        $items = [];
        while (!$this->at($close)) {
            $items[] = $this->parseValue();
        }
        $this->next($close);
        $this->end();
        return $items;
    }

    /**
     * `%{...}` (map) or `%Name{...}`/`%Name[...]` (struct), the struct
     * optionally carrying the version it was written with: `%Name(3){...}`.
     */
    private function parsePercent(): mixed
    {
        $at = $this->pos;
        $this->next('PERCENT');
        if ($this->at('LBRACE')) {
            $this->begin('map', $at);
            $pairs = $this->parseEntries(false);
            $this->end();
            return $this->buildMap($pairs);
        }
        $this->begin('struct', $at);
        try {
            return $this->parseStructBody($this->tokens[$at]->start);
        } finally {
            $this->end();
        }
    }

    /**
     * The rest of a struct after its `%`: its name, its version, and its
     * keyed or positional body. `$start` is where its `%` is, for its
     * errors.
     */
    private function parseStructBody(int $start): mixed
    {
        $name = $this->next('IDENTIFIER')->value;
        $version = $this->parseStructVersion();
        if ($this->at('LBRACE')) {
            // Entries keyed by a name are the struct's fields; any other
            // map key (DXN.md §1.6) is an extra field's (§4.7).
            $entries = $this->parseEntries(true);
            MapKey::checkPairs($entries);
            $pairs = [];
            $extra = [];
            foreach ($entries as [$key, $value]) {
                $field = DXNStruct::extraKey($key);
                if (is_string($field)) {
                    $pairs[] = [$field, $value];
                } else {
                    $extra[] = [$key, $value];
                }
            }
            return $this->materializeStruct($name, DXNStruct::keyed($name, $pairs, $version, $extra), $start);
        }
        [$values, $extra] = $this->parsePositionalBody();
        return $this->materializeStruct($name, DXNStruct::positional($name, $values, $version, $extra), $start);
    }

    /**
     * `[items... entries...]`: a positional struct's items, then its extra
     * fields as entries (DXN.md §4.7). An item is a value not followed by
     * `=>`; once an entry has begun, an item is a syntax error.
     *
     * @return array{0: list<mixed>, 1: list<array{0: mixed, 1: mixed}>}
     */
    private function parsePositionalBody(): array
    {
        $this->begin('positional');
        $this->next('LBRACKET');
        $this->mark('open');
        $values = [];
        $entries = [];
        while (!$this->at('RBRACKET')) {
            if ($this->at('MAP_KEY')) {
                $this->begin('entry');
                $key = new DXNKeyword($this->next('MAP_KEY')->value);
                $this->mark('key');
                $entries[] = [$key, $this->parseValue()];
                $this->end();
                continue;
            }
            $start = $this->peek()->start;
            $keyAt = $this->pos;
            $value = $this->parseValue();
            if ($this->at('ARROW')) {
                $this->rewrapAsEntry($keyAt);
                $this->next('ARROW');
                $this->mark('arrow');
                $this->keyAllowed($value, $start);
                $entries[] = [$value, $this->parseValue()];
                $this->end();
            } elseif ($entries !== []) {
                throw new ParseError("a positional struct's items come before its extra fields", $start);
            } else {
                $values[] = $value;
            }
        }
        $this->next('RBRACKET');
        $this->end();
        MapKey::checkPairs($entries);
        return [$values, array_map(static fn (array $entry): array => [DXNStruct::extraKey($entry[0]), $entry[1]], $entries)];
    }

    /**
     * The `(3)` of `%Name(3){...}` -- the schema version a struct was
     * written with (DXN.md §4.8), a positive integer -- or `null` when
     * there is none.
     */
    private function parseStructVersion(): ?int
    {
        if (!$this->at('LPAREN')) {
            return null;
        }
        $this->next('LPAREN');
        $tok = $this->next('INTEGER');
        $version = filter_var($tok->value, FILTER_VALIDATE_INT);
        if ($version === false || $version <= 0) {
            throw new ParseError("a struct version is a positive integer, got {$tok->value}", $tok->start);
        }
        $this->next('RPAREN');
        return $version;
    }

    private function materializeStruct(string $name, DXNStruct $struct, int $start): mixed
    {
        if ($this->registry === null) {
            return $struct;
        }
        try {
            // Fail-fast, decode-time enforcement -- a schema violation
            // is an ordinary parse error, same channel as bad syntax.
            // Which schema a struct is read with depends on its version
            // ({@see Reader}).
            $read = Reader::read($this->registry, $struct);
        } catch (SchemaValidationError $err) {
            // A schema violation keeps its own category (DXN.md §5); it
            // is not a syntax error for having been found here. It is
            // placed at this struct's `%` unless a struct inside it was
            // already where it was found -- the innermost place wins, as
            // the reference's.
            throw $err->placedAt($start);
        } catch (SchemaCompileError $err) {
            // A resolver's refusal of the schema files it found keeps its
            // category, placed at the struct that asked for them.
            throw new DXNError($err->getMessage(), 'schema', $start, $err, $err->category());
        } catch (DXNError $err) {
            throw $err->offset === null ? new DXNError($err->getMessage(), $err->stage, $start, $err, $err->category) : $err;
        } catch (\Throwable $err) {
            throw new ParseError("struct \"{$name}\" violates its schema: {$err->getMessage()}", $start);
        }
        if ($read === null) {
            return $struct;
        }
        [$value, $this->registry] = $read;
        return $value;
    }

    /**
     * Refuses `$key` as a map key (DXN.md §1.6) unless it may be one,
     * placed at `$offset`, where the key was written -- as the reference
     * places it. Checked here rather than once the map is built, where
     * nothing says which entry it was.
     */
    private function keyAllowed(mixed $key, int $offset): void
    {
        try {
            MapKey::check($key);
        } catch (KeyNotAllowed $err) {
            throw $err->placedAt($offset);
        }
    }

    /**
     * `{map_entry*}`, ordered pairs -- used by both `map_lit` and
     * `struct_keyed`. The colon-shorthand key (`MAP_KEY` token) is
     * keyword-typed per the grammar (`name: value` and `:name =>
     * value` are the exact same value), so it's wrapped as
     * {@see DXNKeyword} here just like an explicit `:name` would be
     * -- callers (map vs struct) each reduce that down to whatever
     * shape they actually need.
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function parseEntries(bool $ownNode): array
    {
        if ($ownNode) {
            $this->begin('keyed');
        }
        $this->next('LBRACE');
        $this->mark('open');
        $pairs = [];
        while (!$this->at('RBRACE')) {
            $this->begin('entry');
            if ($this->at('MAP_KEY')) {
                $key = new DXNKeyword($this->next('MAP_KEY')->value);
                $this->mark('key');
                $pairs[] = [$key, $this->parseValue()];
            } else {
                $keyStart = $this->peek()->start;
                $key = $this->parseValue();
                $this->next('ARROW');
                $this->mark('arrow');
                $this->keyAllowed($key, $keyStart);
                $pairs[] = [$key, $this->parseValue()];
            }
            $this->end();
        }
        $this->next('RBRACE');
        if ($ownNode) {
            $this->end();
        }
        return $pairs;
    }

    /**
     * @param list<array{0: mixed, 1: mixed}> $pairs
     * @return array<int|string, mixed>|\JOetjen\Dextrin\Value\DXNMap
     */
    private function buildMap(array $pairs): array|\JOetjen\Dextrin\Value\DXNMap
    {
        return MapBuilder::build($pairs, static fn (string $message): ParseError => new ParseError($message));
    }

    /**
     * `@{...}` (set), or `@identifier value` (tag_form), or
     * `@regex(flags) value` -- a header carries a regex's flags, as
     * `%Name(3)` carries a version, and stands on no other tag (DXN.md
     * §1.2). Discard is already stripped in {@see self::parseValue()}.
     */
    private function parseAt(): mixed
    {
        $at = $this->pos;
        $atTok = $this->next('AT');
        if ($this->at('LBRACE')) {
            return $this->parseSet($at);
        }
        $this->begin('tag', $at);
        $nameTok = $this->next('IDENTIFIER');
        $this->mark('name');
        try {
            if ($this->at('LPAREN')) {
                $this->next('LPAREN');
                $flags = $this->next('IDENTIFIER')->value;
                $this->next('RPAREN');
                if ($nameTok->value !== 'regex') {
                    throw new ParseError("@{$nameTok->value} takes no (...) header; only @regex(flags) does", $atTok->start);
                }
                return $this->parseRegexTag($flags, $nameTok->start);
            }
            return $this->dispatchTag($nameTok->value, $nameTok->start);
        } finally {
            $this->end();
        }
    }

    private function dispatchTag(string $name, int $offset): mixed
    {
        if (!isset(self::BUILT_IN_TAGS[$name])) {
            $decoder = $this->registry?->fetchTag($name);
            if ($decoder !== null) {
                try {
                    return $decoder($this->parseValue());
                } catch (\Throwable $err) {
                    throw new ParseError("tag \"{$name}\" decoder failed: {$err->getMessage()}", $offset);
                }
            }
            return new DXNCustomTag($name, $this->parseValue());
        }

        return match ($name) {
            'ordered' => $this->parseOrderedTag($offset),
            'sorted-set' => $this->parseSortedSetTag($offset),
            'array' => $this->parseArrayTag($offset),
            'uuid' => DXNUuid::fromString($this->parseStringValue($name, $offset)),
            'uri' => DXNUri::fromString($this->parseStringValue($name, $offset)),
            'bytes' => $this->parseBytesTag($name, $offset),
            'bits' => $this->parseBitsTag($offset),
            // Dates and times are ISO 8601 strings under their tag
            // (DXN.md §1.2, Jan, 2026-10-20), read by Temporal.
            'date' => Temporal::parseDate($this->parseStringValue($name, $offset)),
            'time' => Temporal::parseTime($this->parseStringValue($name, $offset)),
            'datetime' => Temporal::parseDateTime($this->parseStringValue($name, $offset)),
            'local-datetime' => Temporal::parseLocalDateTime($this->parseStringValue($name, $offset)),
            'duration' => Temporal::parseDuration($this->parseStringValue($name, $offset)),
            // No `default` arm: self::BUILT_IN_TAGS' keys are exactly
            // this match's arms, so PHPStan already proves this is
            // exhaustive -- adding a tag to that list without a
            // matching arm here is a static error, not a runtime one.
            'regex' => $this->parseRegexTag('', $offset),
        };
    }

    // The map is read here rather than through parseValue(), which would
    // build a map that forgets its order; the tree records the same
    // value, body and map nodes parseValue() would.
    private function parseOrderedTag(int $offset): DXNOrderedMap
    {
        $this->begin('value');
        $this->skipDiscards();
        if (!$this->at('PERCENT')) {
            throw new ParseError('@ordered requires a map literal argument', $offset);
        }
        $this->begin('body');
        $this->begin('map');
        $this->next('PERCENT');
        if (!$this->at('LBRACE')) {
            throw new ParseError('@ordered requires a map literal argument', $offset);
        }
        $pairs = $this->parseEntries(false);
        $this->end();
        $this->end();
        $this->end();
        \JOetjen\Dextrin\MapKey::checkPairs($pairs);
        return new DXNOrderedMap($pairs);
    }

    private function parseSortedSetTag(int $offset): DXNSortedSet
    {
        $inner = $this->parseValue();
        if (!$inner instanceof DXNSet) {
            throw new ParseError('@sorted-set requires a set literal argument', $offset);
        }
        return DXNSortedSet::fromSet($inner);
    }

    /** @return list<mixed> */
    private function parseArrayTag(int $offset): array
    {
        $inner = $this->parseValue();
        if (!$inner instanceof DXNList) {
            throw new ParseError('@array requires a list literal argument', $offset);
        }
        return $inner->toArray();
    }

    /**
     * `@bits {"..." n}` (DXN.md §1.4): base64 and a bit count; a whole
     * number of bytes reads as bytes, which is what it is.
     */
    private function parseBitsTag(int $offset): DXNBits|DXNBytes
    {
        $wanted = '@bits takes {"base64" bit-count}, the count matching the bytes';
        $inner = $this->parseValue();
        if (!$inner instanceof DXNTuple || count($inner->items) !== 2) {
            throw new ParseError($wanted, $offset);
        }
        [$text, $count] = $inner->items;
        $data = is_string($text) ? base64_decode($text, true) : false;
        if ($data === false || !is_int($count)) {
            throw new ParseError($wanted, $offset);
        }
        try {
            return DXNBits::from($data, $count);
        } catch (\InvalidArgumentException) {
            throw new ParseError($wanted, $offset);
        }
    }

    private function parseBytesTag(string $name, int $offset): DXNBytes
    {
        $decoded = base64_decode($this->parseStringValue($name, $offset), true);
        if ($decoded === false) {
            throw new ParseError('@bytes requires a valid base64 string argument', $offset);
        }
        return new DXNBytes($decoded);
    }

    private function parseStringValue(string $tagName, int $offset): string
    {
        $value = $this->parseValue();
        if (!is_string($value)) {
            throw new ParseError("@{$tagName} requires a string argument", $offset);
        }
        return $value;
    }
}
