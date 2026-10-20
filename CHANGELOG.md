# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **New type forms** (DXN.md §4.1, as dextrin 47d6d1d): `{:not t}`,
  `:never` (JSON Schema's `false`), and element types for the
  collections that had none: `{:array-of t}`, `{:ordered-map-of k v}`,
  `{:sorted-set-of t}`.
- **`Format`** and the `Form` enum (DXN.md §6, as dextrin's
  `Dextrin.Format`): `mediaType()`, `extension()`, `form()` -- the three
  media types, and any `+dxn` suffix as text -- and `identifies($body,
  $form)`, a cheap prefix test of whether a body is worth decoding as a
  form: binary by the envelope's magic, text and schemas by a first
  significant `%` after whitespace, comments and a header. Lists and
  scalars stay unrecognised on purpose: they overlap with JSON (Jan,
  2026-10-20).
- `Schema::compileAll($docs, $baseRegistry)`: several `.dxns` documents
  compiled into one registry, in order; the first that does not compile
  throws, naming which -- as the reference's `compile_all/2`.
- **Dotted names** (DXN.md §1.1, as dextrin 0d1c7f5): segments joined by
  `.`, with at most one `/` -- `%Foo.Bar/Baz{...}`, `acme.billing/invoice`,
  `:a.b`, `a.b: 1`. Two slashes or an empty segment are refused.
- **`FileResolver::forPaths()` follows DXN.md §4.6** (as dextrin
  fd34537): the four layouts, the first search path deciding, a file's
  version its schema's, one version found once, every version loaded. A
  resolver answers a list of every version found and throws
  `SchemaCompileError` for files it cannot use.
- **`bits`**, as DXN.md 1.1 defines it (dextrin afbb00d): `DXNBits`
  holds a run of bits that is not whole bytes -- the bytes, the unused
  bits zero, and the count -- written `@bits {"oA==" 3}` and `T_BITS`
  (216). `DXNBits::from()` answers `DXNBytes` for a whole number of
  bytes, as bits and bytes are one value space. `:bits` in a schema takes
  bits and bytes, `:bytes` only whole bytes; bits are no map key.
- The format guides in `dxn/` are DXN.md 1.1's text: format version 1.1,
  `bits`, the private range 200-216, and §5's `document/*` errors.

### Changed

- **The README opens with a warning** (Jan, 2026-10-20): the library
  is under heavy development and not for production use before `1.0.0`;
  things can and will break, and the specification can and will change,
  between any two releases until then.
- **`{:one-of ...}` means exactly one variant matches** (Jan,
  2026-10-20; JSON Schema's `oneOf`, as dextrin 47d6d1d), and
  **`{:any-of ...}`** is the new "one or more" (`anyOf`) -- what `one-of`
  meant before. Breaking: a schema whose `one-of` variants overlap now
  refuses a value two of them match; write `any-of` to keep the old
  meaning. `{:nilable t}` is sugar for `{:any-of :nil t}`.
- **The pretty layout keeps short collections on one line** (Jan,
  2026-10-20), as `dextrin` does: the top-level collection still breaks,
  one entry per line, however short; a nested one is written on one
  line, its entries single-space separated, when that line -- indent and
  key included -- fits the width (`width`, 80). `Dextrin::encode()` with
  `pretty: true`, `Document::format()` and `dextrin format` all lay out
  this way. A nested collection holding a comment, or a blank line
  between its entries, stays over lines.
- **A field typed with a namespaced reference takes a struct of that
  name** (DXN.md §4.7): a field typed `geo/Address` accepts
  `%geo/Address{...}` and refuses `%Address{...}` -- the other way round
  from before, when a resolved schema kept its file's name. Breaking for
  documents that wrote the bare name. `forPaths()`' resolver answers a
  list now, each schema under the name asked for.
- **A 1.0 schema says it is one**, as the reference's (dextrin afbb00d):
  `fields: @ordered %{...}`, `closed: true` and `forbidden: [...]` are
  refused (`schema/compile`) naming DXN 1.0's syntax and what 1.1 writes
  instead, rather than as a field of no known type.

### Fixed

- **A map key that is not allowed, and a schema violation, say where
  they are** when decoding text: `key/not-allowed` at the key, a schema
  error at its struct's `%` (the innermost one), as the reference places
  them. They had no offset. A refused key is named as DXN writes it,
  `[1] cannot be a map key`, not as PHP's type name.
- **A value written well that is not one is a `syntax` error** at the
  token it began with (`DXN.md` §5), as the reference answers: an
  impossible date or time, a uuid or datetime that is not one, an unknown
  string escape, a zero denominator. They came back with no category and
  no offset.
- `Document::format()` wrote the header without its space
  (`@dxn"1.0"`); it writes the tag, one space and the version, however
  they were spaced.
- `Document::format()` dropped a document's final newline; it now ends
  with one exactly when the source did. `dextrin format` still writes one
  final newline.

## [0.2.0] - 2026-10-19

Versioned schemas, extra fields, the conformance suite's adapter and a
document that keeps its comments. Breaking: a 0.1.x `.dxns` file has
to be rewritten, and decoding a checked struct answers a `DXNStruct`.

### Migrating from 0.1.x

- **`.dxns` files.** The body lists the fields directly, in the order
  `fields: @ordered %{...}` gave them, and a schema without a version
  is version 1:

  ```text
  # 0.1.x
  User: %schema{closed: true, fields: @ordered %{name: :string, email?: :string}}
  # 0.2.0
  User: %schema{name: :string, email?: :string}
  ```

  An unconverted file is refused by `Schema::compile` (a
  `SchemaCompileError`, `schema/compile`: `field "fields": unrecognized
  type expression`), never read as something else.
- **`closed:`, `forbidden:` and `refine-fn:` are gone.** A struct may
  carry fields its schema does not declare. Where `closed: true` refused
  them, decode with `strict: true` (or `$registry->putStrict(true)`),
  which refuses every undeclared field as `schema/undeclared-field`. A
  `forbidden:` list or a refine predicate becomes a check in the
  schema's materializer (`$registry->putStructMaterializer()`), which may
  throw.
- **A checked struct decodes to a `DXNStruct`**, not an array. A
  materializer that answers its fields keeps the old shape:
  `$registry = $registry->putStructMaterializer('User', fn (string
  $name, int $version, array $fields): array => $fields)` -- the
  registry's methods answer a new registry.
- **`.dxnb` written by 0.1.x still reads**, and so does text: its structs
  carry no version, and a struct without a version is read as the latest
  version loaded. Proven against bytes 0.1.0 wrote, in
  `tests/Binary/V010Test.php`.
- **Rewrite stored 0.1.x data before you load a version 2.** Once a
  version 2 is loaded, an unversioned struct is read as version 2, and a
  `.dxnb` struct with version 1's fields no longer matches it
  (`schema/field-count`). Decoded and encoded again with 0.2.0, the same
  value is written as `User(1)`, and from then on it is read as version 1
  and handed to the materializer to upgrade.

### Added

- `Document` -- a `.dxn` document with everything it holds, its comments,
  blank lines and commas as well as its value, mirroring
  `Dextrin.Document`; immutable, every edit answering a new one:
  `parse()` (`decode()`'s options and errors), `toValue()`, `render()`
  (the source, byte for byte, when nothing was edited), `format()` (laid
  out, every comment kept), `replace()`, `delete()` and `insert()` by
  path, and `update()`, which makes a new value by the fewest edits and
  refuses where guessing would lose a comment. The parser records the
  tree as it parses (`Text\Parser::parseTree()`), so it is the same
  parser as `decode()`'s.
- **`dextrin format` keeps comments** (`--mode pretty`, the default);
  `--mode condense` still drops them, a comment ending its line.
- `dextrin conformance` answers `format` and `edit` cases.
- The tutorial's §8, "Evolving a schema": a worked example of versions,
  reading across them, the materializer, the positional risk and
  `dextrin compat`. The cheatsheet gains versions and extra fields.
- `Schema::compat()` and `dextrin compat OLD NEW` -- the version
  discipline check (DXN.md §4.8), for CI against the previous release's
  schema files: a version whose content changed without a new version,
  or a version that went down, fails; what the latest version's
  materializer has to upgrade is listed either way.
- `Canonical::print()` -- the canonical form of a value (DXN.md §1.5):
  one rendering fixed down to the byte, with maps and sets in canonical
  order, floats by one exact algorithm that no `precision` ini setting
  can change, and one fixed set of string escapes, so values from
  different implementations compare as text.
- `DXNError::$category` and the `HasCategory` interface -- the error's
  kind as DXN.md §5 names it (`syntax`, `binary`, `encode/unsupported`,
  ...), so errors compare across implementations without comparing
  messages. A value with no DXN meaning now throws `UnsupportedValue`.
- `Conformance::answer()` and `dextrin conformance REQUEST ANSWER` --
  this implementation's adapter for the Dextrin Conformance Suite.
- `DXNMap` -- a decoded map a native PHP array cannot hold exactly, with
  its keys compared by canonical form (DXN.md §1.6). `MapKey` holds the
  map-key rule.

- **Versioned schemas** (DXN.md §4.8). `%schema(3){...}` is version 3
  of its name, and one with no header is version 1. A registry keeps every
  loaded version side by side: `addStructSchema()` refuses a version that
  is already loaded (`schema/duplicate-version`), `replaceStructSchema()`
  hot-swaps one that is loaded and refuses one that is not
  (`schema/not-loaded`), and `fetchStructSchema($name)` answers the
  latest, `fetchStructSchema($name, $version)` exactly that one.
- **A struct literal carries its version**: `%User(3){...}` and
  `%User(3)[...]` decode to a `DXNStruct` whose `version` is 3, and are
  written back with it. A version of zero or less is refused.
- `DXNStruct::head()`, `DXNStruct::withPairs()` and `withValues()`.
- **Every struct written against a schema carries the latest loaded
  version of it**, in text (`%User(3){...}`, and `%User(1){...}` when
  version 1 is the only one) and in `.dxnb`, where the type name becomes
  `"User(3)"`. A struct with no schema keeps its own version, or none.
  `Registry::asWritten()` decides; `DXNStruct::typeName()` and
  `parseTypeName()` spell it, and `Printer::writtenHead()` is the head
  both text writers share. A `.dxnb` type name whose version is not a
  positive integer is a `binary` error.
- **A struct is read according to the version it was written with**
  (DXN.md §4.8, `Schema\Reader`), in text and binary alike. The latest
  version, or none, is read exactly. A newer version is read against the
  latest: text by name, binary by position, dropping items beyond the
  reader's fields and leaving missing optional fields absent. An older
  version that is loaded is read with its own schema and handed to the
  materializer, and refused as `schema/no-materializer` without one; an
  older version that is not loaded is refused as `schema/unknown-version`.
- **One materializer per schema, for every version** (DXN.md §4.8).
  `Registry::putStructMaterializer()` takes a closure of
  `(string $name, int $version, array $fields)`; it is called for every
  decoded struct of its schema, upgrades older versions, and its result is
  checked against the latest schema -- a throw or a rejected result is
  `schema/materializer`. `DXNSchemaMaterializingProvider::dxnMaterialize()`
  takes the same arguments.
- **Without a materializer, a checked struct decodes to a `DXNStruct`** at
  its schema's latest version, fields in the schema's order, instead of a
  marked-key array -- so it encodes back to the text it came from. An
  optional field that was absent stays absent instead of becoming `null`.
- `Validator::materialize()` is replaced by `resolve()`; building the value
  is `Schema\Reader`'s job.
- **A struct may carry extra fields** (DXN.md §4.7), kept in
  `DXNStruct`'s new `extra` list and written back with it: in text as
  entries -- after a positional struct's items,
  `%User(3)["a" "a@x" nick: "b"]` -- and in `.dxnb` as one trailing map
  under the new private tag 215. A keyed struct's undeclared names, and any
  entry keyed by something other than a name, are extras. An extra never
  shadows a declared field (`schema/shadowed-field`); the materializer
  receives extras with the declared fields; `Dextrin::decode(..., strict:
  true)` refuses them (`schema/undeclared-field`,
  `Registry::putStrict()`).
- A keyed struct entry whose key is not a name -- `%Point{1 => 2}` -- is an
  extra field instead of an error.
- **A struct is matched only by a struct** (DXN.md §4.7). A field typed
  `User` accepts a `DXNStruct` named `User`, or the class registered for
  `User`; a bare array, any other value, and a class registered for no
  schema or another are type errors. Until now every non-struct value was
  accepted.
- **Nothing is converted to make a value match its type.** The
  schema-driven coercion and the `$coerce` parameter of
  `Dextrin::encode()`, `encodeBinary()`, `Schema::validateEncode()` and
  `validateEncodeTree()` are removed (`Schema\Coercion` and
  `Schema\NotCoercible` are gone): a `float` for a `:rational` or
  `:decimal` field, a string for `:char`, an array for `:list` or
  `:tuple` and a `\DateTimeImmutable` for a date or time field are now
  refused. A host value always means the same DXN type, schema or not.

### Changed

- Licensed under the Apache License 2.0 (`Apache-2.0`); it was MIT.
- The format guides in `dxn/` are `DXN.md`'s current text, copied from
  the Elixir reference; the tutorial no longer calls PHP "JS".
- **A native `\DateTimeInterface` encodes as a `datetime`**, keeping its
  offset, with or without a schema (DXN.md §4.7). It was written as an
  empty struct named after its class. `DXNDateTime::fromNative()` is
  new.
- **A registered class instance carries extra fields**: a public
  property its schema does not declare is written as an extra field, as
  an Elixir struct's undeclared keys are. It was dropped.
- **An absent field is never written as `nil`** (DXN.md §4.3): a property
  an instance lacks, or a typed one left uninitialised, is left out of
  text, and in `.dxnb`, which fills every position, written as its
  default or refused (`schema/missing-field`). The writers and the
  validator wrote `nil`, which the same schema then refused to read
  back.
- **Every schema violation carries its DXN.md §5 category**:
  `schema/field-count`, `schema/missing-field`, `schema/type-mismatch`
  and `schema/shadowed-field`, decoding text or binary and encoding
  alike. They came back as `syntax` from text, `binary` from binary,
  and with no category from an encode. `SchemaValidationError`
  implements `HasCategory`, whose `category()` may now answer null.
- `dextrin conformance` loads a case's `schemas` and honours `strict`.

- **A map key must be one of the eight types of DXN.md §1.6** (string,
  keyword, integer, uuid, date, bytes, symbol, char). Any other key is
  refused as `key/not-allowed` (`KeyNotAllowed`) when decoding text or
  binary and when encoding, in maps and ordered maps alike.
- **A map decodes to a `DXNMap` when a native array cannot hold it
  exactly**: a char, uuid, date, bytes or symbol key; `1` beside `"1"`;
  a string key such as `"5"` that PHP would turn into an integer; an
  empty map, or one keyed `0..n-1`. These used to fail to decode, collide
  as an error, or -- for `"5"` and the empty map -- come back as a
  different value. A map of ordinary keys is still a native array.
- **A schema's body is its fields, in order**:
  `User: %schema(3){name: :string, email: :string, id?: :uuid}`. The
  `fields: @ordered %{...}` wrapper is gone, and so are `closed:`,
  `forbidden:` and `refine-fn:`; `Schema::compile()`,
  `Compiler::compile()` and `FileResolver::forPaths()` lose their
  `$predicates` argument. `SchemaCompileError` carries the category
  `schema/compile`.
- Two structs are equal only when their versions are too.

### Fixed

- A symbol whose name is not an identifier is refused by the text
  writers (`encode/unsupported`): `Dextrin::encode()`, the pretty printer
  and `Canonical::print()` wrote its name verbatim, which read back as
  something else, or as nothing (`''`). `.dxnb` keeps any name.

- A keyed struct written to `.dxnb` went on the wire in the order of its
  keys rather than its schema's field order, so it came back with its
  fields swapped.
- A float, boolean or `nil` map key is refused instead of being silently
  turned into another key: `1.5` became `1`, `true` became `1` and `nil`
  became `:""`.
- A walk over a struct's fields -- stripping the schema marker, the
  check on the way out, and the binary decoder's own copying -- rebuilt
  the struct from its name and fields; it now changes only the fields,
  so nothing else the struct carries is lost.

## [0.1.0] - 2026-08-14

### Changed

- Renamed the release branch from `master` to `main`, matching
  `dextrin`'s and `node-dextrin`'s own convention (`AGENTS.md`,
  `.github/workflows/ci.yml`'s push trigger).
- `map`'s bare-PHP-array key representation now marks a genuinely
  string-typed key with a single leading NUL byte, distinguishing it
  from a `DXNKeyword` key with the same text (`MapBuilder`'s own new
  `toDxnMapKey()`, the reverse of its existing key-coercion). A
  keyword-derived key (the overwhelmingly common case, e.g. `%{x: 1}`)
  stays exactly as ergonomic as before -- unprefixed, `$map['x']` --
  but a genuine string key with the same text (`%{"x" => 1}`) no
  longer collapses onto it. This closes a real cross-encoder
  inconsistency: `Text\Printer`/`Text\Formatter` used to *guess*
  "keyword" for any identifier-shaped string key on encode, while
  `Binary\Encoder` always assumed "string" -- meaning the exact same
  decoded PHP value could produce a `.dxn` and a `.dxnb` that decoded
  back to two different things (found via a cross-language round-trip
  check against `node-dextrin`/`php-dextrin`'s sibling ports). Both
  encoders now agree, using the mark to tell the two apart correctly
  instead of guessing.
  - A `keyword` key and a same-text `string` key no longer collide at
    decode time either (they land in distinct PHP array slots now) --
    `%{name: 1, "name" => 2}` decodes to
    `['name' => 1, "\0name" => 2]` instead of throwing.
  - `MapBuilder`'s existing `integer`-vs-canonical-numeric-`string`
    key collision (`%{1 => "a", "1" => "b"}`) is unaffected -- a
    string key PHP would already auto-coerce to an `int` key on its
    own is deliberately left unmarked, so it still collides with a
    bare `integer` key exactly as before.
- `Schema\Validator`'s default struct materialization (decoding a
  struct whose schema has no custom materializer registered) now
  marks its resulting field-name keys as genuine `string` keys (via
  `MapBuilder::markStringKey()`), matching Elixir's own default
  (`Atom.to_string/1` on each field name) and node-dextrin's. Left
  unmarked, those keys re-encoded as `keyword` instead of `string`,
  diverging from what the same document decodes to in either of this
  library's sibling ports -- found via the same cross-language
  round-trip check as the fix above, once it moved past the first
  inconsistency into schema-materialized values.

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

- Generated API documentation ([phpDocumentor](https://phpdoc.org/),
  run via its official `phpdoc/phpdoc` Docker image against
  `phpdoc.dist.xml` -- no source changes needed), published to GitHub
  Pages via `.github/workflows/docs.yml` on every push to `main`,
  mirroring `dextrin`'s own ExDoc/Hex Docs setup. `composer run docs`
  builds it locally into `doc/` (gitignored); requires Docker.
- `src/Dextrin.php`'s own class docblock now names its reference
  implementation and sibling port (`dextrin` on Hex.pm, `node-dextrin`
  on npm), alongside the existing README "Other language
  implementations" section.
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
  analysis, `.editorconfig`, git flow (`main`/`develop`), GitHub
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
