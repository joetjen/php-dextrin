# dextrin_php

A PHP port of [`dextrin`](https://github.com/joetjen/dextrin), an
Elixir implementation of DXN (Data eXchange Notation): a
human-writable text format (`.dxn`), a compact binary format
(`.dxnb`) built on CBOR, and a schema format (`.dxns`) — all three
sharing one in-memory value representation and one extension
mechanism. A sibling of [`dextrin_js`](https://github.com/joetjen/dextrin_js),
the Node.js port of the same library.

**Status: early port, in progress.** The value model (`src/Value/`),
the `.dxn` text codec, and the `.dxnb` binary codec are implemented.
Remaining: the `.dxns` schema system and polish. See `CHANGELOG.md`
for exactly what's landed.

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
`dextrin_js`, its closest sibling — it's by design, documented at the
point of difference. PHP forces some genuinely different calls than
JS did: no arbitrary-precision integer natively (no GMP extension
assumed available; `bcmath` backs the overflow case), and exactly one
flexible native container (`array`) where JS had distinct
`Array`/`Map`/`Set`. Notably:

- `integer` is a **hybrid**: native PHP `int` when a value fits in 64
  bits (the overwhelming common case — ordinary `+`/`-`/comparisons
  just work), a `DXNInteger` (bcmath-backed) wrapper only on genuine
  overflow. Unlike `dextrin_js`, which always uses `BigInt` for
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
- Encoding a plain PHP object (`stdClass` or any class with public
  properties) auto-produces a DXN `struct` literal named after its
  class — `(object) ['x' => 1]` encodes as `%stdClass{x:1}` — without
  needing to build a `DXNStruct` by hand first.

Full mapping table, and the reasoning behind every choice, lives in
this project's development plan for now and will move into this
README as the port progresses.

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
library — bignum precision, timestamp integer-only encoding, and the
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

(Not yet published — this is Phase 0, scaffold only.)

## License

MIT — see [LICENSE](LICENSE).
