# Contributing to php-dextrin

Thanks for considering a contribution. This document covers what you
need to know before opening an issue or a pull request.

## Getting started

```sh
git clone <this repository>
cd php-dextrin
composer install
composer test
```

That should complete with no failures on a clean checkout. If it
doesn't, please open an issue before doing anything else — that's a
bug in its own right.

## Project layout

- `src/Dextrin.php` — the public API: static `decode()`/`encode()`/
  `decodeBinary()`/`encodeBinary()`.
- `src/DXNError.php` — the one exception type every `dextrin` failure
  mode throws.
- `src/Value/` — the DXN value model: native PHP values where one
  fits losslessly (`null`, `bool`, `int`/`float`, `string`, bare
  `array` for `map`/`array`), and a small `DXN*` wrapper class
  (`DXNSymbol`, `DXNTuple`, `DXNOrderedMap`, ...) everywhere else,
  plus `Equality.php` (the shared structural equality/ordering
  kernel).
- `src/Text/` — the `.dxn` pipeline: a hand-written recursive-descent
  `Lexer`/`Parser` (no parser-generator dependency — `dxn/DXN.md`
  §1's grammar is small and normative enough that one isn't needed),
  `Printer`/`Formatter` (the reverse direction), `Escapes`,
  `Temporal` (date/time/duration parsing shared by both directions).
- `src/Binary/` — the `.dxnb` pipeline: `Encoder`/`Decoder` (a direct,
  hand-rolled CBOR codec over `pack`/`unpack`), `Writer` (the
  low-level byte-header writer), `Tags`, `BinaryError`.
- `src/Schema.php` + `src/Schema/` — `.dxns` schema compilation and
  validation, including the schema-driven encode-time coercion
  (`Coercion.php`) that has no Elixir `dextrin` equivalent (ported
  from `node-dextrin`).
- `src/Registry.php` — the shared struct/custom-tag/type-alias
  extension point, immutable-update-style (every `with*`/`put*`
  method returns a new `Registry`).
- `bin/dextrin` — the `dextrin` CLI (`decode`/`encode`/`format`/
  `validate` subcommands).
- `priv/schema/std.dxns` — `Std`'s source (the standard named-type
  library).
- `tests/` — PHPUnit, mirroring `src/`'s own layout.
- `dxn/` — the implementation-independent DXN format spec, ported
  from `dextrin`'s own `guides/dxn/`.
- `QUICKSTART.md`/`TUTORIAL.md`/`EXAMPLES.md`/`CHEATSHEET.md` — this
  project's own PHP-API-level guides.

## Making a change

1. **Tests first, or at least alongside.** A lexer/parser- or
   codec-level change should come with a test exercising actual
   input/output behavior, not just "does this parse."
2. **Round-trip both directions, where it applies.** A change to the
   shared value representation (`src/Value/`) or a wrapper class
   should be checked against both `.dxn` and `.dxnb`.
3. **Where inputs form a space bigger than a handful of examples
   usefully covers**, prefer a property-based/fuzz-style test (a
   hand-rolled randomized loop inside PHPUnit — no dedicated
   property-testing package is used here) over enumerating more
   example cases by hand.
4. **Run the full verification pass before opening a PR:**

   ```sh
   composer run precommit
   ```

   Expands to `vendor/bin/phpstan analyse src --level=8` followed by
   the full PHPUnit suite.
5. **Match the existing code style.** `declare(strict_types=1)` at the
   top of every PHP file, native PHP 8 type declarations everywhere
   PHP itself can express them (not just PHPDoc), a PHPDoc block on
   every public class/method explaining *why* when that's non-obvious
   (never restating what a well-named method already makes clear).
   `DXN*` classes under `src/Value/` keep the `DXN`-prefixed name even
   inside their own namespace — matches this project's other PHP
   libraries' own convention.
6. **Keep documentation current.** Update PHPDoc, `README.md`,
   `CHANGELOG.md` (`[Unreleased]`), and any guide the change touches
   in the same commit — stale docs are worse than none. Before
   documenting any example, actually run it.

## Reporting bugs

Please include: the input (`.dxn`/`.dxnb`/`.dxns` source, or a minimal
excerpt reproducing the issue), what you expected, and what actually
happened (including the full `DXNError` — message and any offset — if
one was thrown). "Doesn't parse" and "doesn't work" are much harder to
act on than a specific input/expected/actual triple.

## License

By contributing, you agree that your contributions will be licensed
under the project's [MIT license](LICENSE).
