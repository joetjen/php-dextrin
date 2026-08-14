# Quickstart

## Install

```sh
composer require joetjen/dextrin
```

Requires PHP 8.2 or later, with the `bcmath` and `mbstring` extensions
(both bundled with most PHP distributions).

## Decode and encode

```php
use JOetjen\Dextrin\Dextrin;

$value = Dextrin::decode('%{name: "Ada", born: 1815, active: true}');
// => ['name' => 'Ada', 'born' => 1815, 'active' => true]

echo Dextrin::encode($value, pretty: true);
// %{
//   name: "Ada"
//   born: 1815
//   active: true
// }
```

`.dxn` (text) and `.dxnb` (binary, `Dextrin::decodeBinary()`/
`Dextrin::encodeBinary()`) decode to the same in-memory value —
`decode()`/`encode()` throw `JOetjen\Dextrin\DXNError` on failure
(malformed input, or a value with no DXN representation), never
return an error value.

## The CLI

```sh
vendor/bin/dextrin format config.dxn --mode pretty
vendor/bin/dextrin validate config.dxn --schema config.dxns --as Config
vendor/bin/dextrin encode config.dxn --out config.dxnb
```

## Where to go next

- [TUTORIAL.md](TUTORIAL.md) — a guided walkthrough of this library's
  own features (custom tags, schemas, materializers, the standard
  library, a config-loader example).
- [EXAMPLES.md](EXAMPLES.md) — worked examples across common use cases.
- [CHEATSHEET.md](CHEATSHEET.md) — quick reference once you know the
  shape of things.
- [dxn/DXN.md](dxn/DXN.md) — the DXN format itself, independent of
  this (or any) implementation, if you want the full normative
  specification.
