# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Project scaffold: `composer.json` (`joetjen/dextrin`, PSR-4
  `JOetjen\Dextrin\`), PHPUnit test setup, PHPStan (level 8) static
  analysis, `.editorconfig`, git flow (`master`/`develop`), GitHub
  Actions CI (PHP 8.2/8.3/8.4 matrix) and dependabot.
- Core DXN value model (`src/Value/`): wrapper classes for every DXN
  type without a lossless native PHP equivalent (`DXNInteger`,
  `DXNDecimal`, `DXNRational`, `DXNChar`, `DXNSymbol`, `DXNKeyword`,
  `DXNList`, `DXNTuple`, `DXNOrderedMap`, `DXNSet`, `DXNSortedSet`,
  `DXNStruct`, `DXNDate`, `DXNTime`, `DXNTimestamp`, `DXNDateTime`,
  `DXNDuration`, `DXNUuid`, `DXNUri`, `DXNBytes`, `DXNRegex`,
  `DXNCustomTag`), each with documented two-way conversion helpers
  to/from its closest native PHP counterpart where one genuinely
  exists. `DXNInteger` follows this port's own hybrid integer rule —
  native `int` when a value fits in 64 bits, this `bcmath`-backed
  wrapper only on genuine overflow. DXN `array` (sequential) and
  `map` (associative) decode/encode as bare native PHP `array` —
  the one place this port's collection strategy diverges from
  "wrap everything without a lossless native fit," since PHP's array
  shape distinction (`array_is_list()`) is a genuine, reliable native
  signal for exactly those two DXN types.
- Shared structural equality/ordering kernel (`src/Value/Equality.php`,
  `Equality::dxnEquals()`/`dxnCompare()`/`canonicalKey()`/`kindOf()`)
  backing every value shape, native and wrapped alike — including
  treating any plain PHP object with public properties (not just
  `DXNStruct`) as a `struct` for equality/encoding purposes.
- `.dxn` text codec (`src/Text/`, `Dextrin::decode()`/`Dextrin::encode()`):
  a hand-written recursive-descent `Lexer`/`Parser` directly off
  `dxn/DXN.md` §1's EBNF (no parser-generator dependency), a
  single-line `Printer`, and a multi-line `Formatter`
  (`encode($value, pretty: true)`). Verified against `dxn/DXN.md` §3's
  own worked example and a hand-rolled randomized round-trip test
  (this project's property-testing convention, no dedicated package).
  `Lexer` operates on the source split into codepoints rather than
  raw bytes, so token offsets are codepoint indices, not byte offsets
  — a deliberate choice distinct from both the Elixir and JS ports'
  own offset units.
- `DXNError` (`src/DXNError.php`) — one exception type for every
  `dextrin` failure mode, `stage`/`offset` fields, wraps any thrown
  exception via `DXNError::wrap()`. Idiomatic PHP from the start
  (unlike Elixir's `{:ok, _} | {:error, _}` tuples) — no "divergence"
  note needed the way the JS port's own `DXNError` carries one.
- Map-building fidelity decisions, confirmed with the user: a DXN
  `map`'s keys reduce to whatever PHP array key they naturally coerce
  to (a `DXNKeyword` key reduces to its bare name string, matching
  `map_entry`'s shorthand form exactly — so a keyword key and a
  string key with the same text are no longer distinguishable after
  decode, a documented limit of the bare-array `map` representation).
  Two *distinct* DXN keys that would coerce to the same PHP array
  slot (`1` and `"1"`; `:name` and `"name"`) is a decode-time
  `DXNError`, not silent data loss — ordinary duplicate keys still
  follow normal last-write-wins map semantics.
- Public entry point (`JOetjen\Dextrin\Dextrin` facade class):
  `Dextrin::decode()`/`Dextrin::encode()`; `.dxnb` binary and `.dxns`
  schema support land in later phases.
- `.dxnb` binary codec (`src/Binary/`, `Dextrin::decodeBinary()`/
  `Dextrin::encodeBinary()`): a hand-rolled CBOR `Encoder`/`Decoder`
  over raw PHP strings (no `Buffer`-equivalent needed — PHP strings
  are already binary-safe), `Writer` (low-level header/item builders),
  `Tags` (the private tag block, 200–214, plus regex-flags/duration
  -bitmask byte<->array conversions), `BinaryError`. Verified against
  `dxn/DXN.md` §3's own worked example, a by-type round-trip suite,
  the same hand-rolled random-value generator the text codec's own
  property test uses (now shared, `tests/Support/RandomValueGenerator.php`),
  and a 500-iteration fuzz test (random bytes after a valid envelope,
  plus every truncation of a real document) confirming malformed
  input always fails as a clean `BinaryError`, never an uncaught
  crash.
  - **The one sharp edge this phase's own plan flagged in advance**:
    CBOR major 0's argument can reach `2^64-1`, which exceeds
    `PHP_INT_MAX` (`2^63-1` — PHP's `int` is signed 64-bit, unlike
    the JS port's unbounded `bigint`). `Writer`/`Decoder` handle every
    CBOR item-header size as a numeric string throughout, comparing
    via `bccomp()` and only casting to a native `int` once a branch
    has already proven the value small enough — verified directly at
    the byte level, including right at the `PHP_INT_MAX` boundary
    itself, not just via a round-trip that might not exercise it.
  - Value sharing (`dxn/DXN.md` §2.5, tags 28/29) and string-reference
    sharing (§2.4, tags 256/25) are decode-side only, same as the JS
    port and for the same reason (spec-mandatory to *accept*, optional
    to *produce*).
  - `.dxnb` structs are always positional on the wire (§2.1) — a
    keyed `DXNStruct` round-trips through binary with its field
    *values* intact but not its keyed-ness or field names, a
    spec-mandated asymmetry from the text codec (which preserves both
    struct shapes exactly), not a bug.
  - A `DXNDecimal` with a positive exponent — which has no distinct
    text-literal form (see the text codec's own note) — round-trips
    *exactly* through binary, since CBOR tag 4's `[exponent, mantissa]`
    payload supports any signed exponent directly. The one place this
    port's two codecs genuinely differ in fidelity, in binary's favor.
- `MapBuilder` (`src/MapBuilder.php`): the map-key-coercion/collision
  -detection logic factored out of `Text\Parser` so `Binary\Decoder`
  can reuse it exactly for the untagged-map case — `@ordered`'s (text)
  and `T_ORDERED`'s (binary) own map-shaped payloads both bypass it
  deliberately, since `ordered-map`'s pairs can hold any key type
  without needing PHP-array-key coercion at all.
