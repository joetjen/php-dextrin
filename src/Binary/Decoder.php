<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Binary;

use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\MapKey;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\Validated;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Schema\Reader;
use JOetjen\Dextrin\Schema\SchemaValidationError;
use JOetjen\Dextrin\Value\DXNBits;
use JOetjen\Dextrin\Value\DXNBytes;
use JOetjen\Dextrin\Value\DXNChar;
use JOetjen\Dextrin\Value\DXNCustomTag;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNDecimal;
use JOetjen\Dextrin\Value\DXNDuration;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNLocalDateTime;
use JOetjen\Dextrin\Value\DXNOrderedMap;
use JOetjen\Dextrin\Value\DXNRational;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNSet;
use JOetjen\Dextrin\Value\DXNSortedSet;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTime;
use JOetjen\Dextrin\Value\DXNTuple;
use JOetjen\Dextrin\Value\DXNUri;
use JOetjen\Dextrin\Value\DXNUuid;
use JOetjen\Dextrin\Value\Nanos;

/**
 * `.dxnb` decoder -- the mirror image of {@see Encoder}. Envelope
 * (`magic` `version` `cbor_item`) checked once at the top; everything
 * below that is a single recursive {@see self::decodeItem()}, keyed
 * on CBOR major type first, then on tag -- matching `dxn/DXN.md`
 * §2.2's table row order.
 *
 * `readHead()`'s argument is always a numeric string, never a native
 * `int` -- CBOR major 0's argument can reach `2^64-1`, which exceeds
 * `PHP_INT_MAX`, the same boundary {@see Writer}'s own doc explains.
 *
 * One case needs care that the JS port didn't: `@ordered`'s payload
 * is an untagged CBOR map (major 5), and the *plain*, untagged major
 * -5 case decodes through {@see MapBuilder} (this port's own
 * PHP-array-key coercion for `map`) -- which `ordered-map` must NOT
 * go through, since its pairs can hold any key type. `decodeTag()`
 * special-cases `T_ORDERED` to read raw pairs directly, exactly
 * mirroring how `Text\Parser::parseOrderedTag()` avoids the same trap.
 */
final class Decoder
{
    private readonly string $buf;
    private int $pos;
    private ?Registry $registry;

    /** @var list<mixed> indexed by tag-28 registration order. */
    private array $sharedItems = [];

    /** @var list<list<string>> innermost scope last. */
    private array $stringrefStack = [];

    private function __construct(string $buf, int $pos, ?Registry $registry)
    {
        $this->buf = $buf;
        $this->pos = $pos;
        $this->registry = $registry;
    }

    public static function decode(string $bytes, ?Registry $registry = null): mixed
    {
        if (strlen($bytes) < 3 || $bytes[0] !== 'D' || $bytes[1] !== 'X' || ord($bytes[2]) !== 1) {
            throw new BinaryError('missing or invalid .dxnb envelope (expected magic "DX" + version 1)');
        }
        $decoder = new self($bytes, 3, $registry);
        $value = $decoder->decodeItem();
        if ($decoder->pos !== strlen($bytes)) {
            $trailing = strlen($bytes) - $decoder->pos;
            throw new BinaryError("trailing bytes after top-level value ({$trailing} left over)", $decoder->pos);
        }
        // The true top of decoding -- see Text\Parser::parse()'s
        // identical stripping and its own doc for why.
        return Validated::strip($value);
    }

    private function readByte(): int
    {
        if ($this->pos >= strlen($this->buf)) {
            throw new BinaryError('unexpected end of input', $this->pos);
        }
        return ord($this->buf[$this->pos++]);
    }

    private function take(int $len): string
    {
        if (strlen($this->buf) - $this->pos < $len) {
            throw new BinaryError('unexpected end of input (truncated item)', $this->pos);
        }
        $out = substr($this->buf, $this->pos, $len);
        $this->pos += $len;
        return $out;
    }

    /**
     * @return array{0: int, 1: numeric-string}
     *
     * @phpstan-impure
     */
    private function readHead(): array
    {
        $first = $this->readByte();
        $major = $first >> 5;
        $info = $first & 0x1f;
        if ($info < 24) {
            return [$major, (string) $info];
        }
        if ($info === 24) {
            return [$major, (string) ord($this->take(1))];
        }
        if ($info === 25) {
            return [$major, (string) self::unpackUint($this->take(2), 'n')];
        }
        if ($info === 26) {
            return [$major, (string) self::unpackUint($this->take(4), 'N')];
        }
        if ($info === 27) {
            return [$major, Writer::bytesToBigDigits($this->take(8))];
        }
        throw new BinaryError("malformed CBOR item header (reserved additional info {$info})", $this->pos);
    }

    private function decodeItem(): mixed
    {
        if ($this->pos >= strlen($this->buf)) {
            throw new BinaryError('unexpected end of input', $this->pos);
        }
        $first = ord($this->buf[$this->pos]);
        if (($first >> 5) === 7) {
            return $this->decodeMajor7($first & 0x1f);
        }
        [$major, $arg] = $this->readHead();
        return $this->decodeByMajor($major, $arg);
    }

    private function decodeMajor7(int $info): mixed
    {
        $this->pos++; // consume the header byte decodeItem() only peeked
        return match ($info) {
            20 => false,
            21 => true,
            22 => null,
            25 => throw new BinaryError('half-precision (16-bit) CBOR floats are not supported', $this->pos),
            26 => self::unpackFloat($this->take(4), 'G'),
            27 => self::unpackFloat($this->take(8), 'E'),
            default => throw new BinaryError("unsupported CBOR item (major 7, arg {$info})", $this->pos),
        };
    }

    private static function unpackFloat(string $bytes, string $format): float
    {
        $unpacked = unpack($format, $bytes);
        if ($unpacked === false) {
            throw new \LogicException("unreachable: unpack('{$format}', ...) failed on a byte string of the exact required length");
        }
        return $unpacked[1];
    }

    private static function unpackUint(string $bytes, string $format): int
    {
        $unpacked = unpack($format, $bytes);
        if ($unpacked === false) {
            throw new \LogicException("unreachable: unpack('{$format}', ...) failed on a byte string of the exact required length");
        }
        return $unpacked[1];
    }

    /** @param numeric-string $arg */
    private function decodeByMajor(int $major, string $arg): mixed
    {
        return match ($major) {
            0 => DXNInteger::represent($arg),
            1 => DXNInteger::represent(bcsub('-1', $arg, 0)),
            2 => new DXNBytes($this->take((int) $arg)),
            3 => $this->decodeText((int) $arg),
            4 => new DXNList($this->decodeNItems((int) $arg)),
            5 => MapBuilder::build($this->decodeMapPairsRaw((int) $arg), fn (string $m): BinaryError => new BinaryError($m, $this->pos)),
            6 => $this->decodeTag((int) $arg),
            default => throw new BinaryError("unsupported CBOR item (major {$major}, arg {$arg})", $this->pos),
        };
    }

    private function decodeText(int $len): string
    {
        $raw = $this->take($len);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw new BinaryError('invalid UTF-8 in text item', $this->pos);
        }
        $this->registerStringref($raw);
        return $raw;
    }

    /**
     * @return list<mixed>
     *
     * @phpstan-impure
     */
    private function decodeNItems(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->decodeItem();
        }
        return $items;
    }

    /** @return list<array{0: mixed, 1: mixed}> */
    private function decodeMapPairsRaw(int $pairCount): array
    {
        $pairs = [];
        for ($i = 0; $i < $pairCount; $i++) {
            $key = $this->decodeItem();
            $value = $this->decodeItem();
            $pairs[] = [$key, $value];
        }
        return $pairs;
    }

    private function decodeTag(int $tagNumber): mixed
    {
        return match ($tagNumber) {
            Tags::T_SHAREABLE => $this->decodeShareable(),
            Tags::T_SHARED_REF => $this->decodeSharedRef(),
            Tags::T_STRINGREF_NAMESPACE => $this->decodeStringrefNamespace(),
            Tags::T_STRINGREF => $this->decodeStringref(),
            Tags::T_STRUCT => $this->decodeStruct(),
            // Read as raw pairs, bypassing MapBuilder -- see this
            // class's own doc for why.
            Tags::T_ORDERED => self::orderedMap($this->decodeOrderedPairs()),
            default => $this->buildTagged($tagNumber, $this->decodeItem()),
        };
    }

    /** @return list<array{0: mixed, 1: mixed}> */
    private function decodeOrderedPairs(): array
    {
        if ($this->pos >= strlen($this->buf)) {
            throw new BinaryError('unexpected end of input', $this->pos);
        }
        [$major, $arg] = $this->readHead();
        if ($major !== 5) {
            throw new BinaryError('malformed ordered-map (tag ' . Tags::T_ORDERED . ') payload: expected a CBOR map (major 5)', $this->pos);
        }
        return $this->decodeMapPairsRaw((int) $arg);
    }

    // ---- value sharing (dxn/DXN.md §2.5) -----------------------------------
    //
    // Decode-side support is spec-mandatory regardless of whether this
    // library's own encoder produces it (which it doesn't yet, see
    // Encoder's own note). Indices are assigned in decode-completion
    // order (post-order), matching a conforming encoder's own order,
    // which also means a tag-29 reference can only point at an
    // already-decoded item: cycles are structurally impossible here,
    // not separately detected.

    private function decodeShareable(): mixed
    {
        $value = $this->decodeItem();
        $this->sharedItems[] = $value;
        return $value;
    }

    private function decodeSharedRef(): mixed
    {
        $index = $this->decodeItem();
        $i = $this->smallIndexOrNull($index);
        if ($i === null) {
            throw new BinaryError('shared value reference (tag ' . Tags::T_SHARED_REF . ') payload must be an integer index', $this->pos);
        }
        if ($i < 0 || $i >= count($this->sharedItems)) {
            throw new BinaryError("shared value reference (tag " . Tags::T_SHARED_REF . ") index {$i} refers to a not-yet-seen item", $this->pos);
        }
        // "MUST resolve every tag-29 reference to an independent
        // copy... no decoder-visible aliasing, no shared mutable
        // identity" (dxn/DXN.md §2.5) -- deep-cloned, not returned by
        // reference.
        return self::deepClone($this->sharedItems[$i]);
    }

    // ---- string-reference sharing (dxn/DXN.md §2.4) ------------------------

    private function decodeStringrefNamespace(): mixed
    {
        $this->stringrefStack[] = [];
        try {
            return $this->decodeItem();
        } finally {
            array_pop($this->stringrefStack);
        }
    }

    private function decodeStringref(): string
    {
        $index = $this->decodeItem();
        $i = $this->smallIndexOrNull($index);
        $scopeIndex = count($this->stringrefStack) - 1;
        $value = ($i !== null && $scopeIndex >= 0) ? ($this->stringrefStack[$scopeIndex][$i] ?? null) : null;
        if ($value === null) {
            throw new BinaryError(
                'string reference (tag ' . Tags::T_STRINGREF . ') index refers to a not-yet-seen string, or there is '
                . 'no active stringref namespace (tag ' . Tags::T_STRINGREF_NAMESPACE . ')',
                $this->pos,
            );
        }
        return $value;
    }

    private function registerStringref(string $text): void
    {
        $scopeIndex = count($this->stringrefStack) - 1;
        if ($scopeIndex >= 0) {
            $this->stringrefStack[$scopeIndex][] = $text;
        }
    }

    private function smallIndexOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if ($value instanceof DXNInteger && $value->fitsInInt()) {
            return $value->toInt();
        }
        return null;
    }

    // ---- tag dispatch (dxn/DXN.md §2.2/§2.3) -------------------------------

    private function buildTagged(int $tag, mixed $value): mixed
    {
        return match ($tag) {
            Tags::T_BIGNUM_POS => $this->buildBignum($value, $tag, false),
            Tags::T_BIGNUM_NEG => $this->buildBignum($value, $tag, true),
            Tags::T_DECIMAL => $this->buildDecimal($value),
            // DXN 0.2's UTC timestamp, epoch microseconds (DXN.md §2.3).
            Tags::T_TIMESTAMP_US => $this->buildDatetime([$this->scaled($value, $tag, 'timestamp', 1000), 0], $tag),
            Tags::T_RATIONAL => $this->buildRational($value),
            Tags::T_URI => $this->expectString($value, $tag, 'uri', static fn (string $v): DXNUri => new DXNUri($v)),
            Tags::T_UUID => $this->buildUuid($value),
            Tags::T_CHAR => $this->buildChar($value),
            Tags::T_SYMBOL => $this->expectString($value, $tag, 'symbol', static fn (string $v): DXNSymbol => new DXNSymbol($v)),
            Tags::T_KEYWORD => $this->expectString($value, $tag, 'keyword', static fn (string $v): DXNKeyword => new DXNKeyword($v)),
            Tags::T_TUPLE => $this->buildFromList($value, $tag, 'tuple', static fn (array $items): DXNTuple => new DXNTuple($items)),
            Tags::T_ARRAY => $this->buildFromList($value, $tag, 'array', static fn (array $items): array => $items),
            Tags::T_SET => $this->buildFromList($value, $tag, 'set', static fn (array $items): DXNSet => new DXNSet($items)),
            Tags::T_SORTED_SET => $this->buildFromList($value, $tag, 'sorted-set', static fn (array $items): DXNSortedSet => DXNSortedSet::fromArray($items)),
            Tags::T_DATE => $this->buildDate($value),
            Tags::T_TIME => $this->buildTime($this->requireSmallInt($value, $tag, 'time')),
            Tags::T_DATETIME => $this->buildDatetime($this->pair($value, $tag, 1), $tag),
            Tags::T_LOCAL_DATETIME => new DXNLocalDateTime($this->requireSmallInt($value, $tag, 'local-datetime')),
            Tags::T_DURATION => $this->buildDuration($value, $tag, 1),
            // DXN 0.2's microsecond encodings, read as what they meant: a
            // tag never changes its meaning (DXN.md §2.3).
            Tags::T_TIME_US => $this->buildTime($this->scaled($value, $tag, 'time', 1000)),
            Tags::T_DATETIME_US => $this->buildDatetime($this->pair($value, $tag, 1000), $tag),
            Tags::T_DURATION_US => $this->buildDuration($value, $tag, 1000),
            Tags::T_REGEX => $this->buildRegex($value),
            Tags::T_CUSTOM => $this->buildCustom($value),
            Tags::T_BITS => $this->buildBits($value),
            default => throw new BinaryError("unrecognized CBOR tag {$tag}", $this->pos),
        };
    }

    /** @param \Closure(string): mixed $build */
    private function expectString(mixed $value, int $tag, string $name, \Closure $build): mixed
    {
        if (!is_string($value)) {
            throw new BinaryError("malformed {$name} (tag {$tag}) payload", $this->pos);
        }
        return $build($value);
    }

    /** @param \Closure(list<mixed>): mixed $build */
    private function buildFromList(mixed $value, int $tag, string $name, \Closure $build): mixed
    {
        if (!$value instanceof DXNList) {
            throw new BinaryError("malformed {$name} (tag {$tag}) payload", $this->pos);
        }
        return $build($value->items);
    }

    private function buildBignum(mixed $value, int $tag, bool $negative): int|DXNInteger
    {
        if (!$value instanceof DXNBytes) {
            throw new BinaryError("malformed bignum (tag {$tag}) payload", $this->pos);
        }
        $n = Writer::bytesToBigDigits($value->data);
        return DXNInteger::represent($negative ? bcsub('-1', $n, 0) : $n);
    }

    private function requireInteger(mixed $value, int $tag, string $name): int|DXNInteger
    {
        if (!is_int($value) && !$value instanceof DXNInteger) {
            throw new BinaryError("malformed {$name} (tag {$tag}) payload", $this->pos);
        }
        return $value;
    }

    /** @return numeric-string */
    private function integerAsString(int|DXNInteger $value): string
    {
        return $value instanceof DXNInteger ? $value->toString() : (string) $value;
    }

    private function requireSmallInt(mixed $value, int $tag, string $name): int
    {
        $i = $this->requireInteger($value, $tag, $name);
        if ($i instanceof DXNInteger) {
            if (!$i->fitsInInt()) {
                throw new BinaryError("malformed {$name} (tag {$tag}) payload: value out of range", $this->pos);
            }
            return $i->toInt();
        }
        return $i;
    }

    private function buildDecimal(mixed $value): DXNDecimal
    {
        if (!$value instanceof DXNList || count($value->items) !== 2) {
            throw new BinaryError('malformed decimal (tag ' . Tags::T_DECIMAL . ') payload', $this->pos);
        }
        [$exponentItem, $mantissaItem] = $value->items;
        $exponent = $this->requireSmallInt($exponentItem, Tags::T_DECIMAL, 'decimal');
        $mantissaStr = $this->integerAsString($this->requireInteger($mantissaItem, Tags::T_DECIMAL, 'decimal'));
        $negative = str_starts_with($mantissaStr, '-');
        $unscaled = $negative ? substr($mantissaStr, 1) : $mantissaStr;
        return new DXNDecimal($negative ? -1 : 1, $unscaled, $exponent);
    }

    private function buildRational(mixed $value): DXNRational
    {
        if (!$value instanceof DXNList || count($value->items) !== 2) {
            throw new BinaryError('malformed rational (tag ' . Tags::T_RATIONAL . ') payload', $this->pos);
        }
        [$num, $den] = $value->items;
        $numStr = $this->integerAsString($this->requireInteger($num, Tags::T_RATIONAL, 'rational'));
        $denStr = $this->integerAsString($this->requireInteger($den, Tags::T_RATIONAL, 'rational'));
        if (bccomp($denStr, '0', 0) <= 0) {
            throw new BinaryError('malformed rational (tag ' . Tags::T_RATIONAL . ') payload', $this->pos);
        }
        return new DXNRational($numStr, $denStr);
    }

    private function buildUuid(mixed $value): DXNUuid
    {
        if (!$value instanceof DXNBytes || strlen($value->data) !== 16) {
            throw new BinaryError('malformed uuid (tag ' . Tags::T_UUID . ') payload', $this->pos);
        }
        return new DXNUuid($value->data);
    }

    private function buildChar(mixed $value): DXNChar
    {
        $i = $this->requireSmallInt($value, Tags::T_CHAR, 'char');
        if ($i < 0 || $i > 0x10FFFF || ($i >= 0xD800 && $i <= 0xDFFF)) {
            throw new BinaryError('malformed char (tag ' . Tags::T_CHAR . ') payload', $this->pos);
        }
        return new DXNChar($i);
    }

    /**
     * `.dxnb` structs are always positional (`dxn/DXN.md` §2.1).
     * Decoded against a compiled schema when `$this->registry` has one
     * for `$name` -- same as {@see \JOetjen\Dextrin\Text\Parser}'s own
     * `materializeStruct()` -- falling back to an opaque `DXNStruct`
     * when it doesn't.
     */
    /**
     * A struct's payload, `[type-name, field..., extras?]`, read item by
     * item so its last item may be its extra fields: a tag-215 map
     * (DXN.md §2.3), legal nowhere else -- anywhere else it is an
     * unrecognized tag. Read in order, so the extras keep the order they
     * were written in.
     */
    private function decodeStruct(): mixed
    {
        if ($this->pos >= strlen($this->buf) || (ord($this->buf[$this->pos]) >> 5) !== 4) {
            throw new BinaryError('malformed struct (tag ' . Tags::T_STRUCT . ') payload', $this->pos);
        }
        $count = (int) $this->readHead()[1];
        if ($count === 0) {
            throw new BinaryError('malformed struct (tag ' . Tags::T_STRUCT . ') payload', $this->pos);
        }
        $items = $this->decodeNItems($count - 1);
        $extra = [];
        if ($count > 1 && $this->atTag(Tags::T_EXTRA)) {
            $this->readHead();
            if ($this->pos >= strlen($this->buf) || (ord($this->buf[$this->pos]) >> 5) !== 5) {
                throw new BinaryError('extra fields (tag ' . Tags::T_EXTRA . ') require a map payload', $this->pos);
            }
            $pairs = $this->decodeMapPairsRaw((int) $this->readHead()[1]);
            MapKey::checkPairs($pairs);
            $extra = array_map(static fn (array $pair): array => [DXNStruct::extraKey($pair[0]), $pair[1]], $pairs);
        } else {
            $items[] = $this->decodeItem();
        }
        return $this->buildStruct($items, $extra);
    }

    /**
     * Whether the next item is a tag of `$number`, without consuming it.
     *
     * @phpstan-impure
     */
    private function atTag(int $number): bool
    {
        $start = $this->pos;
        try {
            if ($this->pos >= strlen($this->buf) || (ord($this->buf[$this->pos]) >> 5) !== 6) {
                return false;
            }
            return (int) $this->readHead()[1] === $number;
        } finally {
            $this->pos = $start;
        }
    }

    /**
     * @param list<mixed> $items the type name, then the fields.
     * @param list<array{0: mixed, 1: mixed}> $extra
     */
    private function buildStruct(array $items, array $extra): mixed
    {
        $typeName = array_shift($items);
        if (!is_string($typeName)) {
            throw new BinaryError('malformed struct (tag ' . Tags::T_STRUCT . ') payload', $this->pos);
        }
        // The type name carries the version the struct was written with,
        // "User(3)" (DXN.md §2.4, §4.8).
        $parsed = DXNStruct::parseTypeName($typeName);
        if ($parsed === null) {
            throw new BinaryError(
                "struct type name \"{$typeName}\" has a version that is not a positive integer",
                $this->pos,
            );
        }
        [$name, $version] = $parsed;
        $struct = DXNStruct::positional($name, $items, $version, $extra);

        if ($this->registry === null) {
            return $struct;
        }
        try {
            // Same fail-fast enforcement as the text parser, through the
            // same {@see Reader}.
            $read = Reader::read($this->registry, $struct);
        } catch (DXNError | SchemaValidationError $err) {
            // A schema violation keeps its own category (DXN.md §5); it
            // is not a binary error for having been found here.
            throw $err;
        } catch (\Throwable $err) {
            throw new BinaryError("struct \"{$name}\" violates its schema: {$err->getMessage()}", $this->pos);
        }
        return $read === null ? $struct : $read[0];
    }

    private function buildDate(mixed $value): DXNDate
    {
        $days = $this->requireSmallInt($value, Tags::T_DATE, 'date');
        $epoch = new \DateTimeImmutable('1970-01-01T00:00:00Z');
        $target = new \DateTimeImmutable('@' . ($epoch->getTimestamp() + $days * 86_400));
        return DXNDate::fromDateTimeImmutable($target);
    }

    // Nanoseconds throughout (DXN.md §2.2, Jan, 2026-10-20). An integer
    // past 64 bits -- CBOR integers are unbounded, and a malformed or
    // adversarial document can hold one -- is refused, not wrapped.

    /**
     * An integer payload times `$scale`: 0.2's microseconds as
     * nanoseconds.
     */
    private function scaled(mixed $value, int $tag, string $name, int $scale): int
    {
        try {
            return Nanos::checked($this->requireSmallInt($value, $tag, $name) * $scale, $name);
        } catch (\InvalidArgumentException) {
            throw new BinaryError("{$name} (tag {$tag}) out of range", $this->pos);
        }
    }

    /**
     * A datetime payload, `[epoch, offset-minutes]`, the epoch times
     * `$scale`.
     *
     * @return array{0: int, 1: int}
     */
    private function pair(mixed $value, int $tag, int $scale): array
    {
        if (!$value instanceof DXNList || count($value->items) !== 2) {
            throw new BinaryError("malformed datetime (tag {$tag}) payload", $this->pos);
        }
        return [$this->scaled($value->items[0], $tag, 'datetime', $scale), $this->requireSmallInt($value->items[1], $tag, 'datetime')];
    }

    private function buildTime(int $nanos): DXNTime
    {
        try {
            return DXNTime::fromNanosecondsSinceMidnight($nanos);
        } catch (\InvalidArgumentException) {
            throw new BinaryError('malformed time (tag ' . Tags::T_TIME . ') payload', $this->pos);
        }
    }

    /** @param array{0: int, 1: int} $parts */
    private function buildDatetime(array $parts, int $tag): DXNDateTime
    {
        try {
            return new DXNDateTime($parts[0], $parts[1]);
        } catch (\InvalidArgumentException) {
            throw new BinaryError("datetime (tag {$tag}) out of range", $this->pos);
        }
    }

    /**
     * `$scale` turns the seventh field into nanoseconds: 1 for tag 220,
     * 1000 for 0.2's microseconds (tag 212).
     */
    private function buildDuration(mixed $value, int $tag, int $scale): DXNDuration
    {
        if (!$value instanceof DXNList || $value->items === []) {
            throw new BinaryError("malformed duration (tag {$tag}) payload", $this->pos);
        }
        $items = $value->items;
        $bitmaskItem = array_shift($items);
        $bitmask = $this->requireSmallInt($bitmaskItem, $tag, 'duration');
        $fields = Tags::durationFieldsPresent($bitmask);
        if (count($fields) !== count($items)) {
            throw new BinaryError('duration bitmask does not match field count', $this->pos);
        }
        /** @var array<string, int> $values */
        $values = [];
        foreach ($fields as $i => $field) {
            $values[$field] = $field === 'nanoseconds'
                ? $this->scaled($items[$i], $tag, 'duration', $scale)
                : $this->requireSmallInt($items[$i], $tag, 'duration');
        }
        return new DXNDuration(...$values);
    }

    private function buildRegex(mixed $value): DXNRegex
    {
        if (!$value instanceof DXNList || count($value->items) !== 2) {
            throw new BinaryError('malformed regex (tag ' . Tags::T_REGEX . ') payload', $this->pos);
        }
        [$pattern, $flagsByte] = $value->items;
        if (!is_string($pattern)) {
            throw new BinaryError('malformed regex (tag ' . Tags::T_REGEX . ') payload', $this->pos);
        }
        $flags = $this->requireSmallInt($flagsByte, Tags::T_REGEX, 'regex');
        return new DXNRegex($pattern, Tags::regexByteToFlags($flags));
    }

    private function buildCustom(mixed $value): mixed
    {
        if (!$value instanceof DXNList || count($value->items) !== 2) {
            throw new BinaryError('malformed custom tag (tag ' . Tags::T_CUSTOM . ') payload', $this->pos);
        }
        [$name, $inner] = $value->items;
        if (!is_string($name)) {
            throw new BinaryError('malformed custom tag (tag ' . Tags::T_CUSTOM . ') payload', $this->pos);
        }
        $decoder = $this->registry?->fetchTag($name);
        if ($decoder === null) {
            return new DXNCustomTag($name, $inner);
        }
        try {
            return $decoder($inner);
        } catch (\Throwable $err) {
            throw new BinaryError("custom tag \"{$name}\" decoder failed: {$err->getMessage()}", $this->pos);
        }
    }

    /**
     * Structural deep clone of a decoded value, used only to resolve
     * a tag-29 shared-value reference into an independent copy. Every
     * `DXN*` class here is `readonly`, so mutation-based aliasing is
     * already structurally impossible -- this still rebuilds
     * compound shapes (the ones with DXN-value-typed fields to
     * recurse into) for full spec compliance and consistency with the
     * JS port's own (mutable-object) behavior; scalar-only-field
     * classes have nothing to alias and are returned as-is.
     */
    private static function deepClone(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::deepClone(...), $value),
            $value instanceof DXNList => new DXNList(array_map(self::deepClone(...), $value->items)),
            $value instanceof DXNTuple => new DXNTuple(array_map(self::deepClone(...), $value->items)),
            $value instanceof DXNSet => new DXNSet(array_map(self::deepClone(...), $value->items)),
            $value instanceof DXNSortedSet => DXNSortedSet::fromArray(array_map(self::deepClone(...), $value->items)),
            $value instanceof DXNOrderedMap => new DXNOrderedMap(array_map(
                static fn (array $p): array => [self::deepClone($p[0]), self::deepClone($p[1])],
                $value->pairs,
            )),
            $value instanceof DXNStruct => $value->isKeyed()
                ? $value->withPairs(array_map(
                    static fn (array $p): array => [$p[0], self::deepClone($p[1])],
                    $value->pairs(),
                ))
                : $value->withValues(array_map(self::deepClone(...), $value->values())),
            $value instanceof DXNCustomTag => new DXNCustomTag($value->name, self::deepClone($value->value)),
            default => $value,
        };
    }

    /**
     * An ordered map, every key one DXN.md §1.6 allows.
     *
     * @param list<array{0: mixed, 1: mixed}> $pairs
     */
    private static function orderedMap(array $pairs): DXNOrderedMap
    {
        \JOetjen\Dextrin\MapKey::checkPairs($pairs);
        return new DXNOrderedMap($pairs);
    }

    /**
     * T_BITS, `[bytes, count]` (DXN.md §2.3): the count must match the
     * bytes; a whole number of them is bytes.
     */
    private function buildBits(mixed $value): DXNBits|DXNBytes
    {
        $malformed = 'malformed bits (tag ' . Tags::T_BITS . ') payload: [bytes, count] with the count matching the bytes';
        if (!$value instanceof DXNList || count($value->items) !== 2) {
            throw new BinaryError($malformed, $this->pos);
        }
        [$data, $count] = $value->items;
        if (!$data instanceof DXNBytes || !is_int($count)) {
            throw new BinaryError($malformed, $this->pos);
        }
        try {
            return DXNBits::from($data->data, $count);
        } catch (\InvalidArgumentException) {
            throw new BinaryError($malformed, $this->pos);
        }
    }
}
