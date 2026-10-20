# php-dextrin

> [!WARNING]
> **Under heavy development: not for production use before `1.0.0`.**
> Things can and will break, and the specification can and will change,
> between any two releases until `1.0.0`. Do not use this library in a
> production system yet.

[![CI](https://github.com/joetjen/php-dextrin/actions/workflows/ci.yml/badge.svg)](https://github.com/joetjen/php-dextrin/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/joetjen/dextrin.svg)](https://packagist.org/packages/joetjen/dextrin)
[![Docs](https://img.shields.io/badge/docs-phpDocumentor-blue.svg)](https://joetjen.github.io/php-dextrin/)

A PHP port of [`dextrin`](https://github.com/joetjen/dextrin), an
Elixir implementation of DXN (Data eXchange Notation): a
human-writable text format (`.dxn`), a compact binary format
(`.dxnb`) built on CBOR, and a schema format (`.dxns`) — all three
sharing one in-memory value representation and one extension
mechanism. A sibling of [`node-dextrin`](https://github.com/joetjen/node-dextrin),
the Node.js port of the same library.

**Status: feature-complete, pre-1.0.** The value model (`src/Value/`),
the `.dxn` text codec, the `.dxnb` binary codec, the `.dxns` schema
system, the CLI, and the guides are all implemented. See
`CHANGELOG.md` for exactly what's landed, and the
[Documentation](#documentation) section below to get started.

## Why use Dextrin?

Most serialization formats pick one point on a spectrum. JSON is
human-writable but has no dates, no bytes, no exact decimals and no
boundary between an integer and a float. Protobuf and Avro are compact
and typed but need a schema compiled ahead of time, and aren't meant for
a person to read or edit. CBOR and MessagePack are compact and
self-describing but binary only. YAML and TOML are made for people, and
have no binary form for machines.

DXN's premise is that a `.dxn` text document and a `.dxnb` binary
document are the *same* value space: 30 scalar, collection, temporal and
extended types ([reference](dxn/DXN.md) §1.3). It is precise
enough for money (decimals), exact ratios (rationals) and integers of
any size, has `struct` and `custom-tag` as first-class, schema-describable
extension points, and its schemas (`.dxns`) are DXN too. You choose text
or binary by what matters where the data is going -- a person reading
it, or bytes on the wire -- and the value is the same either way.

### What each format can say

| | DXN | JSON | YAML | TOML | BSON | MessagePack | CBOR | Protobuf | Ion |
|---|---|---|---|---|---|---|---|---|---|
| Text a person writes | ✓ | ✓ | ✓ | ✓ | – | – | –¹ | –² | ✓ |
| Binary, same values | ✓ | – | – | – | ✓ | ✓ | ✓ | ✓ | ✓ |
| Comments | ✓ | – | ✓ | ✓ | – | – | – | – | ✓ |
| Schema, part of the format | ✓ | ~³ | – | – | – | – | – | –² | ~³ |
| Readable without a schema | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| Integer and float kept apart | ✓ | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Integers beyond 64 bits | ✓ | ~⁴ | ~⁴ | – | – | – | ✓ | – | ✓ |
| Exact decimals | ✓ | – | – | – | ✓ | – | ✓ | – | ✓ |
| Exact ratios | ✓ | – | – | – | – | – | ✓ | – | – |
| Timestamps, dates, times, durations | ✓ | – | ~⁵ | ~⁶ | ~⁷ | ~⁷ | ~¹³ | ~⁸ | ~¹⁴ |
| Bytes | ✓ | – | ~⁵ | – | ✓ | ✓ | ✓ | ✓ | ✓ |
| UUIDs, URIs | ✓ | – | – | – | ~⁹ | – | ✓ | – | – |
| Sets, tuples | ✓ | – | ~⁵ | – | – | – | ~¹⁰ | – | – |
| Map keys that are not strings | ✓ | – | ✓ | – | – | ✓ | ✓ | ~¹¹ | – |
| Typed records (structs) | ✓ | – | – | – | – | – | – | ✓ | – |
| Types an application adds | ✓ | – | ✓ | – | – | ✓ | ✓ | – | ✓ |
| Versioned schemas, checked in CI | ✓ | – | – | – | – | – | – | ~¹² | – |

¹ CBOR has a diagnostic notation for reading, not for authoring.
² Protobuf's text format is for debugging; its schema is a separate
`.proto` file compiled ahead of time.
³ JSON Schema and Ion Schema are written in JSON and Ion, as separate
specifications.
⁴ The grammar allows any size; JavaScript's and PHP's parsers lose the
digits past 53 and 64 bits.
⁵ YAML 1.1 tags (`!!timestamp`, `!!binary`, `!!set`), dropped from 1.2's
core schema.
⁶ Dates, times and date-times; no durations.
⁷ An instant only: no local dates, times or durations.
⁸ The well-known `Timestamp` and `Duration` messages.
⁹ A UUID as binary subtype 4.
¹⁰ Sets by registered tag 258; no tuples.
¹¹ Integer and string keys only.
¹² Field numbers keep old readers working; nothing checks a change.
¹³ Instants (tags 0 and 1) and dates (RFC 8943); no times of day or
durations.
¹⁴ Timestamps of any precision, dates included; no times of day or
durations.

### How big it is

The same four payloads in each format, in bytes, raw and gzipped.
Measured with [`dextrin`](https://github.com/joetjen/dextrin)'s
`bench/format_sizes.exs` (`mix run bench/format_sizes.exs`); the script says how
each format writes the values it has no type for (a decimal or a date
as a string in JSON, for instance).

| | config file | API response, 100 records | time series, 1,000 points | record with a 4 KiB blob |
|---|---:|---:|---:|---:|
| DXN text | 279 / 199 | 19,402 / 4,218 | 63,159 / 13,895 | 5,516 / 4,222 |
| DXN text, pretty | 314 / 216 | 23,504 / 4,249 | 67,161 / 13,961 | 5,527 / 4,234 |
| `.dxnb` | 264 / 212 | 14,185 / 3,802 | 34,006 / 14,381 | 4,145 / 4,168 |
| `.dxnb`, shared | 264 / 212 | 9,217 / 3,752 | 27,017 / 14,019 | 4,145 / 4,168 |
| JSON | 306 / 207 | 19,202 / 4,201 | 56,159 / 13,692 | 5,514 / 4,216 |
| JSON, pretty | 403 / 229 | 26,253 / 4,287 | 74,160 / 14,182 | 5,527 / 4,231 |
| BSON | 312 / 231 | 17,621 / 4,448 | 36,903 / 15,700 | 4,161 / 4,184 |
| MessagePack | 237 / 199 | 12,271 / 3,840 | 25,003 / 11,165 | 4,136 / 4,159 |
| CBOR | 237 / 188 | 12,282 / 3,514 | 25,003 / 11,164 | 4,136 / 4,159 |
| Protocol Buffers | 141 / 111 | 8,552 / 3,702 | 19,000 / 10,832 | 4,122 / 4,145 |

- **DXN text is about JSON's size** -- keys need no quotes -- while
  carrying the types JSON drops. Data full of dates and times is the
  exception, about an eighth larger: each datetime names its type
  (`@datetime "..."`) where JSON writes a bare string.
- **`.dxnb` sits between JSON and the compact binaries.** Value sharing
  writes a repeated key or string once; on the API response that brings
  it within 8% of Protocol Buffers, with no schema needed to read it.
  This package reads shared `.dxnb`; the Elixir and Praxis encoders
  write it.
- **Where it is larger, it is carrying more.** A `.dxnb` datetime keeps
  nanoseconds, its offset and its type, where CBOR's and MessagePack's
  carry whole seconds; a keyword key is tagged as a keyword rather than written as a
  plain string. Protocol Buffers is smallest because its schema, not the
  data, holds the field names.
- **Compressed, the gap narrows.** Gzipped, DXN text stays within about
  12% of `.dxnb`, and JSON within about a quarter of CBOR; past that,
  the choice is about what each format can say.

### What comes back, in each language

What a value is after a round trip through each language's own JSON
parser, and through Dextrin:

| Written | Elixir: JSON / Dextrin | Node.js: JSON / Dextrin | PHP: JSON / Dextrin | Praxis: JSON / Dextrin |
|---|---|---|---|---|
| `9223372036854775808` | integer / integer | **9223372036854776000** / `BigInt` | **float** / `DXNInteger` | integer / integer |
| `19.99` as an exact decimal | **float** / `Decimal` | **float** / `DXNDecimal` | **float** / `DXNDecimal` | **float** / `dextrin.decimal` |
| `1.0` | float / float | **1, an integer** / float | float, **written back as `1`** / float | float / float |
| a datetime | **string** / `DateTime` | **string** / `DXNDateTime` | **string** / `DXNDateTime` | **string** / a `praxis.time` instant |
| bytes | **base64 string** / `Dextrin.Bytes` | **base64 string** / `DXNBytes` | **base64 string** / `DXNBytes` | **base64 string** / `dextrin.bytes` |
| a map keyed by integers | **string keys** / integer keys | **string keys** / integer keys | **string keys** / integer keys | **string keys** / integer keys |
| a set | **list** / `MapSet` | **array** / `Set` | **array** / `DXNSet` | **vector** / set |

A bold entry is a value that did not come back as what was written. With
Dextrin, the same document means the same thing in all four, and every
implementation is held to that by the
[Dextrin Conformance Suite](https://github.com/joetjen/praxis/tree/develop/libs_prx/dextrin_conformance).

### How DXNS compares, as a schema language

A `.dxns` schema is DXN data. It is meant to say everything the schema
languages of the formats above can say, except what has no value or no
meaning in DXN (the reference's
[`design/schema-superset.md`](https://github.com/joetjen/dextrin/blob/develop/design/schema-superset.md)
goes through them construct by construct). YAML, TOML and MessagePack
have no schema language of their own; people use JSON Schema for them.

| | DXNS | JSON Schema | MongoDB `$jsonSchema` | CDDL | Protobuf | Ion Schema |
|---|---|---|---|---|---|---|
| Written in the format it describes | ✓ | ✓ | ✓ | – | – | ✓ |
| Typed records, with versions | ✓ | – | – | – | ~¹ | – |
| Version changes checked in CI | ✓ | – | – | – | ~¹ | – |
| Recursive types | ✓ | ✓ | – | ✓ | ✓ | ✓ |
| Generic types | ✓ | ~² | – | ✓ | – | – |
| A list's items in order | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| A type chosen by a sibling field | ✓ | ~³ | – | ~⁴ | ~⁵ | – |
| String formats, checked the same everywhere | ✓ | ~⁶ | – | ~⁷ | ~⁸ | ~⁷ |
| Decimals, ratios, dates, times, durations as types | ✓ | – | ~⁹ | ~¹⁰ | – | ~¹¹ |
| Bytes holding an encoded document | ✓ | ✓ | – | ✓ | – | – |
| Annotations and free metadata | ✓ | ✓ | ~¹² | – | ✓ | ✓ |
| Closing a type to unknown fields | –¹³ | ✓ | ✓ | ✓ | – | ✓ |
| One test suite every implementation passes | ✓ | ~⁶ | – | – | – | – |

¹ Field numbers keep old readers working; checking a change takes a
tool outside the language, such as `buf breaking`.
² `$dynamicRef`, since draft 2020-12.
³ `if`/`then`/`else`; OpenAPI adds a `discriminator` on top.
⁴ A group choice, `//`, over maps with a literal member.
⁵ `oneof` picks one field of several, not the type of one.
⁶ The official test suite exists, but `format` only annotates unless a
validator opts in, and each implements the formats its own way.
⁷ Regular expressions (CDDL's `.regexp`, Ion Schema's `regex`); CDDL's
`.abnf` takes a grammar.
⁸ Through the separate `protovalidate` rules.
⁹ `decimal` and `date` BSON types; no times of day or durations.
¹⁰ Through tags and the prelude (`tdate`, `decfrac`); no durations.
¹¹ `decimal` and `timestamp`, with precision and offsets; no durations.
¹² `title` and `description` only.
¹³ On purpose: see the next section.

In PHP, a datetime and a local date-time range only from 1677 to 2262
(see [below](#why-a-port-and-why-this-value-model)).

### Why a DXNS type is never closed

JSON Schema's `additionalProperties: false`, Ion Schema's `closed` and
CDDL's maps refuse a field the schema does not list. DXNS has no such
switch, for two reasons:

- **Closing is the reader's choice, not the type's.** A config loader
  wants a typo in a hand-written file caught; a message consumer wants a
  newer producer's fields to pass through untouched. The same `Order`
  schema serves both, so whether unknown fields are refused belongs to
  the decode: `Dextrin::decode($text, $registry, strict: true)` (or
  `Dextrin::decodeBinary()`, or a registry made with
  `$registry->putStrict(true)`) refuses them (`schema/undeclared-field`),
  and without `strict:` they are kept -- in `.dxnb` as one trailing item
  (DXN.md §4.7) -- and written back out.
- **A closed type breaks the next version.** Adding a field to a closed
  type makes every older reader refuse every newer message, which turns
  each compatible change into a breaking one. DXN's versions (below)
  rest on readers that let what they do not know pass.

A check that needs more -- say, refusing exactly the fields another
schema allows -- runs after the decode, on the value it returns, where
the application knows what it means.

### Changing a schema across services

Several services reading and writing the same schema never all deploy
at once. DXNS makes that safe with versions, not with compatibility
modes (DXN.md §4.8):

- **Every schema has a version**, `%schema(3){...}`, and every version
  stays loadable beside the others. A writer always writes the latest it
  has loaded, and says which: `%Order(3){...}`.
- **A newer message reaches an older reader**: it reads the fields it
  knows, by name in text and by position in `.dxnb`, and keeps the rest
  as extra fields. This is why a field is only ever *appended*: in
  `.dxnb`, an older reader takes positions as they were.
- **An older message reaches a newer reader** through the schema's
  *materializer*, one function per schema that upgrades the old fields
  to the latest version (`$registry->putStructMaterializer('Order',
  fn (string $name, int $version, array $fields) => ...)`, which answers
  a new registry). Without one, reading the old version is an error
  (`schema/no-materializer`), not a guess.
- **CI checks the discipline**: `vendor/bin/dextrin compat OLD NEW`
  (`Schema::compat()` underneath) compares the last release's schemas
  with the current ones, each a `.dxns` file or a directory of them, and
  exits non-zero on a problem. A version whose content changed is an
  error -- two documents claiming to be `Order` v3 must mean the same
  thing -- and it lists, for each schema, what changed since each older
  version: the materializer's checklist.

So a change deploys like this:

1. **A compatible change** -- fields appended, nothing else -- deploys
   in either order.
2. **A breaking change** -- a field removed, moved or retyped -- deploys
   **readers first**, each with a materializer for the old version, then
   the writers.
3. **A schema that services both read and write** changes only
   compatibly, or is replaced by a new name (`OrderV2`) beside the old
   one, and the services move over one at a time.

## Why a port, and why this value model

DXN's premise (see the Elixir project's own
[README](https://github.com/joetjen/dextrin#readme) for the full
case) is that a `.dxn` text document and a `.dxnb` binary document
are the *same* value space — scalar, collection, temporal, and
extended types precise enough for money, exact ratios, and
arbitrary-precision integers, with a schema-describable extension
story. `dxn/DXN.md` is the implementation-independent, normative
spec; this library only needs to satisfy it.

Where this library differs from the Elixir original — and from
`node-dextrin`, its closest sibling — it's by design, documented at the
point of difference. PHP forces some genuinely different calls than
JS did: no arbitrary-precision integer natively (no GMP extension
assumed available; `bcmath` backs the overflow case), and exactly one
flexible native container (`array`) where JS had distinct
`Array`/`Map`/`Set`. Notably:

- `integer` is a **hybrid**: native PHP `int` when a value fits in 64
  bits (the overwhelming common case — ordinary `+`/`-`/comparisons
  just work), a `DXNInteger` (bcmath-backed) wrapper only on genuine
  overflow. Unlike `node-dextrin`, which always uses `BigInt` for
  fidelity — PHP has no arithmetic operator overloading, so "always
  wrap" would tax everyday arithmetic project-wide for a magnitude
  that's rare in practice.
- DXN `array` (the fixed-size/indexed type, `@array[...]`) decodes to
  a bare, sequential-keyed PHP `array`; DXN `map` decodes to a bare
  associative PHP `array` — both directions. Every other DXN
  collection type (`list`, `tuple`, `ordered-map`, `set`,
  `sorted-set`) gets its own wrapper class, since PHP's single
  flexible container can't safely host more than those two without
  losing the ability to tell them apart again on `encode`.
- A datetime and a local date-time hold their nanoseconds since 1970
  in a native 64-bit `int`, so they range only from 1677-09-21 to
  2262-04-11; a value outside that is refused, in text, in `.dxnb` and
  in a schema's `format: :datetime`. DXN itself has no such bound, and
  the Elixir reference has none. This is a documented limit of PHP
  itself, of its 64-bit integers, not of DXN; a future PHP may lift
  it.
- Encoding a plain PHP object (`stdClass` or any class with public
  properties) auto-produces a DXN `struct` literal named after its
  class — `(object) ['x' => 1]` encodes as `%stdClass{x:1}` — without
  needing to build a `DXNStruct` by hand first.

Full mapping table in [CHEATSHEET.md](CHEATSHEET.md#value-type-cheatsheet);
the reasoning behind each choice is documented at the point of
difference throughout `src/Value/`.

## `.dxn` text

```php
use JOetjen\Dextrin\Dextrin;

$value = Dextrin::decode('%{x: 1, y: 2}');
// => ['x' => 1, 'y' => 2]  -- map decodes as a bare PHP array

Dextrin::encode($value);
// => '%{x:1,y:2}'

Dextrin::encode($value, pretty: true);
// => "%{\n  x: 1\n  y: 2\n}"
```

`decode()`/`encode()` throw `DXNError` on failure (a malformed
document, or a value with no DXN representation) — idiomatic PHP
from the start, not a tuple-returning API.

## `.dxnb` binary

```php
$bytes = Dextrin::encodeBinary($value); // raw binary string, "DX" + version + CBOR
Dextrin::decodeBinary($bytes);          // => the same value back
```

Hand-rolled CBOR over raw PHP strings (not built on a generic CBOR
library — bignum precision, integer-only nanosecond datetimes, and the
private tag block aren't things a generic library gets right by
default; see `src/Binary/Encoder.php`'s own doc). `decodeBinary()`
accepts documents using the spec's value-sharing extensions
(`dxn/DXN.md` §2.4/§2.5) even though `encodeBinary()` doesn't produce
them yet — accepting is spec-mandatory, producing is optional, and
that asymmetry is deliberate for now (matches the JS port).

One place `.dxnb` and `.dxn` genuinely differ: `.dxnb` structs are
always positional on the wire, so a keyed `DXNStruct` round-trips
through binary with its field values intact but not its keyed-ness or
field names — a spec requirement, not a bug (`.dxn` text preserves
both struct shapes exactly).

## `.dxns` schemas

```php
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;

$schemaDoc = Dextrin::decode(<<<'DXN'
    %{
      Point: %schema{ x: :float, y: :float }
    }
    DXN);
$registry = Schema::compile($schemaDoc, new Registry());

Dextrin::decode('%Point{x: 1.0, y: 2.0}', $registry);
// => DXNStruct{name: 'Point', version: 1, pairs: [['x', 1.0], ['y', 2.0]]}
//    -- schema-validated, decode-time enforcement; a materializer can build your own class instead

Dextrin::encode(['x' => 1.0, 'y' => 2.0], registry: $registry, schema: 'Point');
// => '%Point(1){x:1.0,y:2.0}'
```

A `struct`/tag with no matching registry entry still falls back to an
opaque `DXNStruct`/`DXNCustomTag` — a registry only ever *adds*
capability, never turns an otherwise-valid document into an error.

`encode()`/`encodeBinary()` automatically validate every registered
struct anywhere in a value by default (`validate: false` to skip), and
a `schema:` name additionally validates — and, for a nameless bare
array, *names* — the top-level value itself against one named schema.

**Nothing is converted to make a value match** (DXN.md §4.7): a host
value always means the same DXN type, with or without a schema. A plain
`float` is a `float`, so a `:rational` field refuses it rather than
turning it into a `DXNRational`; say which type a value is with the
`DXN*` classes and their `fromX()` helpers. A field typed `Point`
accepts only a `Point` struct — a `DXNStruct` named `Point`, or the
class registered for it — never a bare array.

Also included, each mirroring its `Dextrin.Schema.*` counterpart:
`Schema::registerProvider()` (let a class's own library ship its
schema without depending on this one, see `src/Schema/Provider.php`),
`Std::registry()`'s standard library of common named types
(`PositiveInteger`, `NonEmptyString`, ...), and
`FileResolver::forPaths([...])`'s resolver from names to `.dxns` files,
by DXN.md §4.6's convention, for `Registry::putResolver()`:

```php
use JOetjen\Dextrin\Schema\FileResolver;
use JOetjen\Dextrin\Schema\Std;

$withStd = Std::registry(); // PositiveInteger, NonEmptyString, ...
$registry = Schema::compile(Dextrin::decode('%{ Age: PositiveInteger }'), $withStd);

$fileBackedRegistry = (new Registry())->putResolver(FileResolver::forPaths(['./schemas']));
```

**Deliberate omission vs. the Elixir and JS ports**: no
`trusted`/`putTrusted` on `Registry`. Both mirror it purely to gate
whether `keyword` decodes as an interned atom/`Symbol.for` (trusted)
or a plain wrapper (untrusted); this port's `keyword` always decodes
as `DXNKeyword` regardless, since PHP has no atom table or global
symbol registry whose exhaustion untrusted input could threaten in the
first place.

## CLI

```sh
vendor/bin/dextrin decode data.dxnb                                  # -> pretty .dxn text on stdout
vendor/bin/dextrin encode data.dxn --out data.dxnb
vendor/bin/dextrin format data.dxn --mode pretty|condense [--in-place]
vendor/bin/dextrin validate data.dxn [--format text|binary] [--schema s.dxns --as Name]
vendor/bin/dextrin compat old-schemas/ schemas/   # changed schemas need new versions
```

## Documentation

- [QUICKSTART.md](QUICKSTART.md) — install and decode/encode your
  first value.
- [TUTORIAL.md](TUTORIAL.md) — a guided walkthrough of this library's
  features: custom tags, schemas, materializers, the standard library,
  a config-loader example.
- [EXAMPLES.md](EXAMPLES.md) — worked examples across common use cases.
- [CHEATSHEET.md](CHEATSHEET.md) — quick reference once you know the
  shape of things.
- [dxn/DXN.md](dxn/DXN.md) — the DXN format itself (text grammar,
  binary encoding), independent of this or any implementation, plus
  its own [tutorial](dxn/TUTORIAL.md), [examples](dxn/DXN_EXAMPLES.md),
  and [cheatsheet](dxn/DXN_CHEATSHEET.md).

## Development

```sh
composer install
composer run precommit
```

`composer run precommit` runs `vendor/bin/phpstan analyse src
--level=8` followed by the full PHPUnit suite — the check this
project expects to pass before every commit.

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to propose changes, and
[CHANGELOG.md](CHANGELOG.md) for release history.

## Installation

```sh
composer require joetjen/dextrin
```

## Other language implementations

DXN's format spec (`dxn/DXN.md`, ported from the Elixir project's own
`guides/dxn/DXN.md`) is implementation-independent — this library,
`dextrin` (Elixir), and `node-dextrin` all satisfy the same document,
and stay close to `dextrin`'s own in-memory shape-per-type choices
wherever their host language allows.

| Language | Package | Source |
| --- | --- | --- |
| Elixir | [`dextrin` on Hex.pm](https://hex.pm/packages/dextrin) | [joetjen/dextrin](https://github.com/joetjen/dextrin) |
| Node.js | [`@joetjen/dextrin` on npm](https://www.npmjs.com/package/@joetjen/dextrin) | [joetjen/node-dextrin](https://github.com/joetjen/node-dextrin) |
| PHP (this project) | [`joetjen/dextrin` on Packagist](https://packagist.org/packages/joetjen/dextrin) | [joetjen/php-dextrin](https://github.com/joetjen/php-dextrin) |

## License

Apache-2.0 — see [LICENSE](LICENSE).
