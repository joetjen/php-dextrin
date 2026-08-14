# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `Text\Printer`/`Text\Formatter`/`Binary\Encoder` now accept an
  optional `Registry` and consult it before falling back to a
  registered application class's own short PHP class name: a class
  registered via `Registry::putStructModule()` under a schema name
  that differs from its own class name now encodes as that schema
  name (`%Point{...}`), not the class name (`%SomeAppSpecificPointImpl{...}`)
  -- matching the JS port's own `print(value, { registry })`/
  `Encoder.encode(value, { registry })` behavior. This was a gap left
  over from the text/binary codecs (Phases 2/3) having been written
  before the schema system (Phase 4) existed, caught while verifying
  the tutorial's own worked examples actually run as documented.
  `Dextrin::encode()`/`encodeBinary()` now thread their own `registry`
  parameter through to these three classes for this purpose.
- The same three classes also now check a class's registered tag
  encoder (`Registry::putTagEncoder()`) before falling back to the
  "any object -> struct" convenience: a class registered only as a
  tag encoder (not a struct schema) now correctly encodes as
  `@tag value`, not `%ClassName{...}` -- found and fixed alongside the
  struct-name gap above, same root cause.

### Added

- `bin/dextrin` CLI (`decode`/`encode`/`format`/`validate`
  subcommands), the PHP analog of the JS port's `bin/dextrin.js` and
  `dextrin`'s own `mix dextrin.*` tasks -- installed as the package's
  Composer `bin` entry.
- `dxn/` -- the implementation-independent DXN specification, ported
  verbatim from `dextrin`/`node-dextrin` (`DXN.md`, `TUTORIAL.md`,
  `DXN_EXAMPLES.md`, `DXN_CHEATSHEET.md`), with only its two
  cross-references to "the JS-API-level equivalent" reworded for this
  port's own `TUTORIAL.md`/`EXAMPLES.md`/`CHEATSHEET.md` instead.
- This project's own `QUICKSTART.md`, `TUTORIAL.md`, `EXAMPLES.md`,
  and `CHEATSHEET.md` -- every code sample verified to actually run
  against this port's real API and output.
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
  `Dextrin::decode()`/`Dextrin::encode()`.
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
- `.dxns` schema system (`src/Registry.php`, `src/Schema.php`,
  `src/Schema/`): `Registry` (immutable-update-style extension point
  for tags/struct schemas/materializers/type aliases/struct modules),
  `Compiler` (a decoded `.dxns` document -> `Compiled` struct schemas
  + named `TypeExpr` aliases, mirroring the fixed 12-form type_expr
  vocabulary and `%schema{}`/`%field{}` grammar from `dxn/DXN.md` §4),
  `TypeExpr::matches()` (shared by decode and encode-time checking),
  `Validator` (`materialize()` for decode, `validateForEncode()`/
  `validateTreeForEncode()` for encode), `Std` (the standard library of
  common named types, `priv/schema/std.dxns`, ported verbatim from
  `dextrin`'s own), `FileResolver` (`Namespace/Name` -> `.dxns`-file
  convention for `Registry::putResolver()`), and `Provider`/
  `DXNSchemaProvider`/`DXNSchemaMaterializingProvider` (lets a class's
  own library ship its schema without depending on this one).
  `Text\Parser`/`Binary\Decoder` now accept an optional `Registry` and
  materialize/validate any struct they recognize a schema for, falling
  back to an opaque `DXNStruct` otherwise as always; `Dextrin::encode()`/
  `encodeBinary()` gained `registry`/`validate`/`coerce`/`schema`
  parameters mirroring `decode()`/`decodeBinary()`'s own `registry`
  parameter.
  - **Schema-driven coercion on encode** (`src/Schema/Coercion.php`,
    `coerce:` parameter, default `true`) — a capability with no Elixir
    `dextrin` equivalent, ported from `node-dextrin` because this port's
    own `DXN*::fromX()` conversion helpers already make the coercion
    table trivial to build.
  - **Deliberate omission vs. the Elixir and JS ports**: no
    `trusted`/`putTrusted` on `Registry` — this port's `keyword` always
    decodes as `DXNKeyword` regardless (see `DXNKeyword`'s own doc), so
    there is no atom-table/`Symbol.for`-exhaustion risk to gate against
    in the first place.
  - **PHP-specific limitation**: rebuilding a registered application
    class instance during encode-time validation writes coerced field
    values back onto a clone via direct property assignment, which
    throws for a `readonly` property — PHP has no generic "reconstruct
    with these fields changed" for those the way a `DXNStruct` gets one,
    unlike the JS port's always-mutable objects.
