# Cheatsheet

Quick reference for common `dextrin` tasks. See the
[tutorial](TUTORIAL.md) if anything here doesn't make sense yet, or
the [DXN cheatsheet](dxn/DXN_CHEATSHEET.md) for format-syntax-level
lookups.

## Decode / encode

```php
Dextrin::decode(string $text, ?Registry $registry = null): mixed
Dextrin::encode(
    mixed $value,
    bool $pretty = false,
    int $indent = 2,
    ?Registry $registry = null,
    bool $validate = true,
    bool $coerce = true,
    ?string $schema = null,
): string
Dextrin::decodeBinary(string $bytes, ?Registry $registry = null): mixed
Dextrin::encodeBinary(
    mixed $value,
    ?Registry $registry = null,
    bool $validate = true,
    bool $coerce = true,
    ?string $schema = null,
): string
```

All four throw `DXNError` on failure, never return an error value.

`$registry` (both directions): enables schema-backed `struct`
decoding/materialization and custom-tag decoding for anything it
knows; also lets a registered application class encode under its
schema's name rather than its own PHP class name.
`$schema` (encode only): validate the top-level value against one
named schema. `$validate` (encode only, default `true`): set `false`
to skip the automatic whole-tree schema check. `$coerce` (encode only,
default `true`): let a mismatched field be coerced toward its
declared type via that DXN type's `fromX` conversion helper before
failing validation; no Elixir `dextrin` equivalent. `$pretty`/`$indent`
(encode only): multi-line, indented output instead of single-line/
compact, and spaces per nesting level when `$pretty` is `true`
(default 2).

## Render an error

```php
try {
    Dextrin::decode($badText);
} catch (\JOetjen\Dextrin\DXNError $e) {
    echo $e->getMessage();
    echo $e->offset;  // codepoint/byte offset, when available
    echo $e->stage;   // 'parse' | 'encode' | 'binary'
}
```

`DXNError` (extends `\RuntimeException`) is thrown for every failure
mode — text-parse, printing, binary, and schema alike — so one `catch`
covers all of `decode()`/`encode()`/`decodeBinary()`/`encodeBinary()`.

## Pretty-print / reformat

```php
Dextrin::encode($value, pretty: true);              // multi-line, indented
Dextrin::encode($value, pretty: true, indent: 4);    // 4 spaces per level instead of the default 2
```

Compact is `encode()`'s default — the smallest text a value can
round-trip through, no line-wrapping or indentation at all. `pretty`
only changes rendering, never what the value decodes back to.

```sh
dextrin format data.dxn --mode pretty|condense [--in-place]
```

## Value-type cheatsheet

```text
nil          null                                   `nil`
boolean      bool                                   `true` / `false`
integer      int | DXNInteger                        native int when it fits 64 bits, DXNInteger (bcmath) on overflow
float        float                                   native -- NAN/INF/-INF supported directly
decimal      DXNDecimal{sign, unscaledValue, exponent}  `19.99M` -- exact
rational     DXNRational{numerator, denominator}      `22/7` -- stored exactly, never auto-reduced
string       string                                  `"..."`
char         DXNChar{codepoint}                       `?a` -- distinct from a 1-codepoint string
symbol       DXNSymbol{name}                          bare identifier, e.g. `foo`
keyword      DXNKeyword{name}                         `:foo` / `foo:` (key position) -- always wraps, no trusted: option
list         DXNList{items}                           `[1 2 3]` -- distinct from array
tuple        DXNTuple{items}                          `{1 2 3}` -- arbitrary length
map          array (bare, associative)                `%{...}` -- the collections decision's one bare-native pairing
ordered-map  DXNOrderedMap{pairs}                      `@ordered %{...}` -- order is part of identity
set          DXNSet{items}                             `@{...}` -- deduped, order-independent
sorted-set   DXNSortedSet{items}                        `@sorted-set @{...}` -- always sorted, deduped
array        array (bare, sequential)                  `@array[1 2 3]` -- the collections decision's other bare-native pairing
struct       DXNStruct{name, kind, pairs|values}        `%Name{...}` / `%Name[...]`, opaque w/o schema
date         DXNDate{year, month, day}                  `~D[YYYY-MM-DD]`
time         DXNTime{hour, minute, second, microsecond} `~T[HH:MM:SS]`
timestamp    DXNTimestamp{epochMicroseconds}            `~U[YYYY-MM-DD HH:MM:SSZ]`
datetime     DXNDateTime{epochMicroseconds, offsetMinutes} `@datetime "..."`
duration     DXNDuration{years, months, ...}            `@duration "P1Y2M"` -- 7 independent fields
uuid         DXNUuid{bytes}                             `@uuid "..."` -- 16 raw bytes, not the text form
uri          DXNUri{value}                              `@uri "..."` -- raw string, not parsed
bytes        DXNBytes{data}                             `@bytes "..."` (base64 in text)
regex        DXNRegex{pattern, flags}                    `~r/.../flags`
custom-tag   DXNCustomTag{name, value}                   `@tag value`, no decoder registered
```

Every wrapper class with a close native counterpart also has
`toX()`/`DXN*::fromX()` conversion helpers (`DXNDate::toDateTimeImmutable()`,
`DXNUuid::fromString()`, ...) — see each class's own file under
[`src/Value/`](src/Value/).

**`array`/`map` are the one deliberate divergence from "wrap
everything"**: PHP's own `array_is_list()` gives one free, reliable
structural signal (sequential vs. associative keys), so those two DXN
types alone decode/encode as a bare native PHP `array` — every other
collection type keeps its own wrapper class so it stays
round-trip-distinguishable on encode.

## Registry: custom tags

```php
$registry
    ->putTag(string $name, \Closure $decoder)                      // throws on invalid value
    ->putTagEncoder(string $class, string $name, \Closure $encoder); // throws if unencodable
```

## Registry: schemas

```php
$registry = Schema::compile(mixed $schemaDoc, Registry $baseRegistry);

$registry
    ->addStructSchema(string $name, Compiled $compiled)      // a new version; throws schema/duplicate-version if loaded
    ->replaceStructSchema(string $name, Compiled $compiled)  // hot-swap a loaded version; throws schema/not-loaded otherwise
    ->putStructMaterializer(string $name, \Closure $materializer)   // fn (string $name, int $version, array $fields): mixed -- every version; result checked against the latest
    ->putStructModule(string $name, string $class)          // for {form: 'reference', name} checks on hand-built instances
    ->putResolver(\Closure $resolver);   // lazy/on-demand schema loading, (string $name): ?Compiled
```

`$registry->fetchStructSchema($name)` answers the latest version, and
`fetchStructSchema($name, $version)` exactly that one.

`FileResolver::forPaths(array $paths)` builds
a ready-made resolver for `Namespace/Name -> paths/Namespace.dxns`.

## Registry: third-party classes (`DXNSchemaProvider`)

For a class defined by a library that doesn't (and shouldn't have to)
depend on `dextrin` itself — see
[`src/Schema/Provider.php`](src/Schema/Provider.php)'s own doc for the
full pattern:

```php
$registry = Schema::registerProvider($registry, $someLibMoneyProvider);
```

`$someLibMoneyProvider` implements `DXNSchemaProvider`
(`dxnSchema()`, `dxnSchemaName()`, `dxnClass()`) or
`DXNSchemaMaterializingProvider` (adds `dxnMaterialize(string $name, int $version, array $fields)`) -- formal
PHP interfaces, unlike the JS port's duck-typed object shape, since
PHP has no structural-typing equivalent to lean on instead.

## Schema validation, outside of decode

```php
Schema::validate(mixed $value, Registry $registry, string $schemaName): true;                    // throws on failure
Schema::validateEncode(mixed $value, Registry $registry, string $schemaName, bool $coerce = true): mixed;  // one named schema, encode-side -- may coerce and return a new value
Schema::validateEncodeTree(mixed $value, Registry $registry, bool $coerce = true): mixed;          // automatic whole-tree walk, encode-side -- may coerce and return a new value
```

## `.dxns` type_expr forms, at a glance

```text
:any                           any value at all
:integer / :string / ...       one keyword per DXN.md §1.3 type name
Address                        bare symbol -- a struct name or a named type
{:list-of T}                   list whose every element matches T
{:set-of T}                    set whose every element matches T
{:tuple-of A B C}              fixed-arity positional tuple
{:map-of K V}                  every key matches K, every value matches V
{:enum :a :b :c}                value must equal one of the given literals
{:one-of A B}                  union -- matches at least one
{:all-of A B}                  intersection -- matches all
{:nilable T}                   sugar for {:one-of :nil T}
{:refine T %{constraints}}     base type + refine constraints (below)
%schema(N){field: T, ...}      struct/record shape, version N (1 without a header)
```

## `refine` constraints

```text
min / max / exclusive-min / exclusive-max / multiple-of   -- integer/float/decimal/rational
min-length / max-length / pattern                          -- string
min-count / max-count                                      -- list/set/tuple/sorted-set
```

## Struct schemas

```
%schema(2){
  required_field:  :integer
  optional_field?: :string             # trailing `?` = optional
  with_default:    %field{type: :integer, default: 0, description: "..."}
}
```

The body is the fields, in order -- that order is the positional order.
`(2)` is the version; a schema without one is version 1. A struct can
say which version it was written with: `%Name(2){...}`.

## Standard named types (`Std`)

```php
$registry = Schema::compile($doc, Std::registry());
```

```text
PositiveInteger, NonNegativeInteger, NegativeInteger, NonPositiveInteger
PositiveFloat, NonNegativeFloat, Percentage
NonEmptyString
NonEmptyList, NonEmptySet
```

## CLI

```sh
dextrin validate data.dxn [--format text|binary] [--schema s.dxns --as Name]
dextrin encode data.dxn [--out data.dxnb]
dextrin decode data.dxnb [--out data.dxn]
dextrin format data.dxn [--mode pretty|condense] [--in-place]
```

No analog of `mix dextrin.gen.schema`/`mix dextrin.gen.unicode`: the
former reflects an existing Elixir struct's fields via
`Map.from_struct/1`, which PHP classes have no built-in equivalent
mechanism for; the latter regenerates `dextrin`'s own generated
Unicode identifier ranges, which this port doesn't need at all — the
lexer uses PCRE's own Unicode property escapes directly instead.
`encode`'s Elixir `--share` flag also has no analog yet: `.dxnb`
encoding here doesn't produce value-sharing output (see the tutorial
§3).
