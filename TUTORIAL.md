# Tutorial

A step-by-step walkthrough of `dextrin`'s features, building up to a
small, complete example: decoding a config file, validating it against
a schema, and re-encoding it to both text and binary. If you want the
DXN *format itself* explained independently of this PHP implementation,
see the [DXN tutorial](dxn/TUTORIAL.md) instead — this one assumes you
already roughly know what `.dxn` text looks like and focuses on the PHP
API around it.

## 1. Decoding and encoding text

```php
use JOetjen\Dextrin\Dextrin;

$value = Dextrin::decode('%{name: "Ada", active: true, score: 19.99M}');
// => [
//      'name' => 'Ada',
//      'active' => true,
//      'score' => DXNDecimal{sign: 1, unscaledValue: '1999', exponent: -2},
//    ]

Dextrin::encode($value);
// => '%{name:"Ada",active:true,score:19.99M}'
```

Notice the value's keys: a `map`'s shorthand keys (`name:`) are
themselves DXN `keyword`s, not bare strings — `%{name: "Ada"}` and
`%{:name => "Ada"}` are the exact same value. This port decodes a
`map` as a bare, native PHP `array`, with every key reduced to
whatever PHP scalar it naturally coerces to — the closest native fit
PHP has, and the one place this port's collection strategy diverges
from "wrap everything without a lossless native equivalent" (see the
[cheatsheet](CHEATSHEET.md) for the full value-type table). A
`keyword` key reduces to its bare name string, exactly matching
`name:`'s shorthand form and keeping the overwhelmingly common case —
a keyword-keyed map — as ergonomic to access as `$value['name']`. A
genuinely *string*-typed key with the same text is marked internally
(a single leading NUL byte, `MapBuilder`'s own doc has the details) so
it doesn't collide with a same-text keyword key, and re-encodes back
as a quoted string rather than silently turning into a keyword:

```php
Dextrin::decode('%{name: 1, "name" => 2}');
// => ['name' => 1, "\0name" => 2] -- two distinct entries, not a collision
```

A DXN `integer` key and a `string` key PHP itself would already coerce
to that same `int` array key (e.g. `1` and `"1"`) is a different,
still-unresolved kind of collision — that one *is* a decode-time
error, not silent data loss:

```php
Dextrin::decode('%{1 => "a", "1" => "b"}');
// => throws DXNError: map keys collide once coerced to a PHP array key ("1"): ...
```

Both directions throw `DXNError` rather than returning a result value
— `decode()` throws on malformed input, `encode()` throws on a value
it can't represent or that fails schema validation. This is idiomatic
PHP from the start (unlike Elixir's `{:ok, _} | {:error, _}` tuples),
not a value-representation choice, so it doesn't affect anything else
in this tutorial.

```php
try {
    Dextrin::decode('%{x: }');
} catch (\JOetjen\Dextrin\DXNError $e) {
    echo $e->getMessage(); // => 'unexpected token RBRACE'
    echo $e->offset;       // => 5
}
```

## 2. What a decoded value looks like

Every DXN type maps to a native PHP value where one exists losslessly,
and to a small wrapper class only where nothing native fits without
losing information:

```php
Dextrin::decode('some-symbol');
// => DXNSymbol{name: 'some-symbol'}

Dextrin::decode(':ok');
// => DXNKeyword{name: 'ok'}

Dextrin::decode('{1 2 3}');
// => DXNTuple{items: [1, 2, 3]}

Dextrin::decode('@ordered %{b: 2, a: 1}');
// => DXNOrderedMap{pairs: [[DXNKeyword{name: 'b'}, 2], [DXNKeyword{name: 'a'}, 1]]}
```

Two things worth noticing right away:

- **`integer` is a hybrid**: native PHP `int` when a value fits in 64
  bits (the overwhelming common case — ordinary `+`/`-`/comparisons
  just work), a `DXNInteger` (`bcmath`-backed) wrapper only on genuine
  overflow. This is a deliberate PHP-specific call, different from
  `node-dextrin`'s "always wrap" choice — PHP has no arithmetic operator
  overloading, so wrapping every integer would tax ordinary arithmetic
  project-wide for a magnitude that's rare in practice.
- **`keyword` always wraps as `DXNKeyword`**, with no `trusted:`-driven
  alternate representation the way the Elixir (real atom) and JS
  (`Symbol.for`) ports have — PHP has nothing analogous to a BEAM atom
  table or `Symbol.for`'s global registry to guard against exhausting,
  so every `DXNKeyword` is an ordinary, garbage-collected object and
  there is no `trusted:` option to pass at all.

See this project's [cheatsheet](CHEATSHEET.md) for the complete
type-to-shape mapping, and each wrapper class's own file under
[`src/Value/`](src/Value/) for conversion helpers (`toX()`/
`DXN*::fromX()`) to and from the closest native PHP equivalent, where
one exists.

## 3. Round-tripping through `.dxnb`

```php
$bytes = Dextrin::encodeBinary($value);
$back = Dextrin::decodeBinary($bytes);
```

`.dxnb` is CBOR underneath, with a 3-byte envelope (`"DX"` + a version
byte) in front for cheap magic-number sniffing. Note one asymmetry:
`decodeBinary()` fully understands DXN's value-sharing extension (a
repeated compound value written once and referenced afterward) when it
encounters it on the wire, but `encodeBinary()` doesn't yet *produce*
shared references — every value round-trips correctly either way, just
not always at the smallest possible byte count for heavily-repeated
data. There is no Elixir `dextrin` equivalent for this note, since its
own encoder does produce them (`share:` option) — it's called out here
because it's a real gap this port hasn't closed yet (same gap the JS
port has).

## 4. Formatting

`encode()` defaults to the smallest text a value can round-trip
through: single-line, minimal whitespace, no line-wrapping or
indentation at all. Pass `pretty: true` for human-readable, multi-line
output instead — e.g. for a CLI or a config file you're about to
commit:

```php
$value = Dextrin::decode('%{name: "Ada", tags: @{:admin :staff}}');

Dextrin::encode($value, pretty: true);
// => "%{\n  name: \"Ada\"\n  tags: @{\n    :admin\n    :staff\n  }\n}"
```

`indent` sets the number of spaces per nesting level (default 2):

```php
Dextrin::encode($value, pretty: true, indent: 4);
// => "%{\n    name: \"Ada\"\n    tags: @{\n        :admin\n        :staff\n    }\n}"
```

`pretty`/`indent` only change *rendering* — the value `decode()` gets
back is identical either way. Schema validation (§6 below) runs the
same regardless too; it's an independent concern from formatting.
`pretty: true` calls `Text\Formatter::pretty()` under the hood, which
is also directly callable on its own (the CLI's
`dextrin format --mode pretty` uses it that way, without going through
`encode()`'s own validation).

Comments are never preserved by either path — `.dxn`'s lexer discards
`#`-comments as trivia before the parser ever sees them, so there's no
comment text left by the time a value exists to reprint.

## 5. Extending: custom tags

`@tag value` is DXN's open extension point for a scalar-wrapping type
with no field structure. Register a decoder and (optionally) an
encoder on a `Registry`:

```php
use JOetjen\Dextrin\Registry;

final class Money
{
    public function __construct(public int $cents) {}
}

$registry = (new Registry())
    ->putTag('my-app/money', fn (mixed $cents): Money => new Money($cents))
    ->putTagEncoder(Money::class, 'my-app/money', fn (Money $m): int => $m->cents);

Dextrin::decode('@my-app/money 500', $registry);
// => Money{cents: 500}

Dextrin::encode(new Money(500), registry: $registry);
// => '@my-app/money 500'
```

With no registration at all, `@my-app/money 500` decodes to an opaque
`DXNCustomTag{name: 'my-app/money', value: 500}` instead of failing —
the same "opaque tagged value, not an error" contract DXN gives every
unrecognized tag.

## 6. Extending: schemas for `struct`

`struct` is DXN's field-structured extension point, and it's
schema-dependent by design: without a compiled schema, `%Point{x: 1,
y: 2}` decodes to an opaque `DXNStruct`. A `.dxns` schema document is
itself just DXN data — no new grammar, no new parser:

```php
use JOetjen\Dextrin\Schema;

$schemaDoc = Dextrin::decode(<<<'DXN'
    %{
      Point: %schema{ x: :integer, y: :integer }
    }
    DXN);

$registry = Schema::compile($schemaDoc, new Registry());

Dextrin::decode('%Point{x: 1, y: 2}', $registry);
// => ['x' => 1, 'y' => 2]
```

Field enforcement (required/optional fields, refinements) happens
automatically at decode time, in both `decode()`
and `decodeBinary()` — a violation comes back as an ordinary thrown
`DXNError`, indistinguishable by shape from a syntax error:

```php
try {
    Dextrin::decode('%Point{x: 1}', $registry);
} catch (\JOetjen\Dextrin\DXNError $e) {
    echo $e->getMessage();
    // => 'struct "Point" violates its schema: missing required field "y"'
}
```

Encoding checks the same thing, automatically, in the other direction
— every `DXNStruct` (or registered application class instance)
anywhere in the value you're encoding gets checked against its own
schema before anything is written:

```php
use JOetjen\Dextrin\Value\DXNStruct;

$bad = DXNStruct::keyed('Point', [['x', 1]]);
Dextrin::encode($bad, registry: $registry);
// => throws DXNError: struct "Point" violates its schema: missing required field "y"
```

Pass `validate: false` to skip this (test fixtures, deliberately
building non-conforming data, a pass-through that shouldn't
second-guess data it isn't the origin of).

## 7. Materializing real PHP classes

By default a struct materializes to a plain, string-keyed array.
Register a materializer to produce your own class instance instead:

```php
final class Point
{
    public function __construct(public mixed $x, public mixed $y) {}
}

$registry2 = $registry->putStructMaterializer('Point', fn (array $f): Point => new Point($f['x'], $f['y']));

Dextrin::decode('%Point{x: 1, y: 2}', $registry2);
// => Point{x: 1, y: 2}
```

To let `{form: 'reference', name: 'Point'}` type-checks and automatic
encode-time validation recognize `Point` instances you build yourself
(never decoded), also declare the class:

```php
$registry3 = $registry2->putStructModule('Point', Point::class);
```

This also lets you encode a `Point` instance directly — no need to
hand-build a `DXNStruct` first, and it encodes under the schema's own
name (`Point`), not necessarily the class's own PHP name, if the two
ever differ:

```php
Dextrin::encode(new Point(1, 2), registry: $registry3);
// => '%Point(1){x:1,y:2}'
```

The `(1)` is the schema's version. A schema with no header is version
1; `%schema(2){...}` is version 2, and a registry can hold several
versions of `Point` side by side. Encoding always writes the latest
one loaded, so the reader knows exactly which shape it is looking at;
a struct written without one, `%Point{...}`, is read as the latest.

A reader that meets a struct written with another version than its
latest does one of three things. A **newer** version is read against
the latest the reader has, matching fields by name in text and by
position in `.dxnb`; anything it does not know is dropped. An **older**
version it has loaded is handed to the schema's materializer to be
upgraded, and refused (`schema/no-materializer`) when there is none.
An older version it has *not* loaded is refused
(`schema/unknown-version`).

**Reading forward in `.dxnb` trusts the positions.** If a newer version
reordered, inserted or removed fields, an older reader puts values in
the wrong fields — with no error at all when the types happen to agree.
A schema whose older readers must keep working only ever *appends*
fields. See DXN.md §4.8 for this and for how to deploy a schema change.

`Registry` is updated immutable-style — every `put*` method returns a
*new* `Registry`, never mutates the one it's called on — so build up
the registry you need through a chain of these calls (as above) or by
reassigning, not by expecting an earlier reference to change under
you.

## 8. Named types and the standard library

Any `.dxns` entry that *isn't* a `%schema{}` defines a reusable named
type instead — purely data, composing the fixed type_expr vocabulary:

```php
$doc = Dextrin::decode(<<<'DXN'
    %{
      PositiveInt: {:refine :integer %{min: 1}}
      Point: %schema{ x: PositiveInt, y: :integer }
    }
    DXN);
```

`Std` ships a small library of common named types
(`PositiveInteger`, `NonEmptyString`, `Percentage`, ...) — pass its
registry as `compile()`'s base registry to use them:

```php
use JOetjen\Dextrin\Schema\Std;

$registry4 = Schema::compile($doc, Std::registry());
```

## 9. Letting a third-party class provide its own schema

Everything so far assumed you write the `.dxns` schema yourself. If
`Point` instead came from a library that doesn't want to (and
shouldn't have to) depend on `dextrin`, that library can ship a small
class implementing `DXNSchemaProvider` (or
`DXNSchemaMaterializingProvider`, when it also wants to control the
decoded shape) — a formal PHP interface, since PHP has no
structurally-duck-typed equivalent the way JS's `tsc --checkJs` gets
away with for the same shape:

```php
use JOetjen\Dextrin\Schema\DXNSchemaMaterializingProvider;

// the library's own point provider class
final class LibPoint
{
    public function __construct(public float $x, public float $y) {}
}

final class LibPointProvider implements DXNSchemaMaterializingProvider
{
    public function dxnSchema(): string
    {
        return '%{ Point: %schema{ x: :integer, y: :integer } }';
    }

    public function dxnSchemaName(): string { return 'Point'; }

    public function dxnClass(): string { return LibPoint::class; }

    public function dxnMaterialize(array $resolved): LibPoint
    {
        return new LibPoint($resolved['x'], $resolved['y']);
    }
}
```

An application depending on both the library and `dextrin` registers
it in one call, wherever it's already building its registry:

```php
$registry5 = Schema::registerProvider(new Registry(), new LibPointProvider());

Dextrin::decode('%Point{x: 1, y: 2}', $registry5);
// => LibPoint{x: 1, y: 2}
Dextrin::encode(new LibPoint(1.0, 2.0), registry: $registry5);
// => '%Point(1){x:1.0,y:2.0}'
```

Implementing only `DXNSchemaProvider` (without `dxnMaterialize()`) is
fine too — decoding then falls back to the same plain field array any
other schema with no materializer produces.

## 10. Putting it together: a small config loader

```php
$schemaSource = <<<'DXN'
    %{
      Server: %schema{
        host:  NonEmptyString
        port:  {:refine :integer %{min: 1, max: 65535}}
        tags?: {:list-of :symbol}
      }
    }
    DXN;

function buildRegistry(): Registry
{
    global $schemaSource;
    $doc = Dextrin::decode($schemaSource);
    return Schema::compile($doc, Std::registry());
}

function loadConfig(string $path): mixed
{
    return Dextrin::decode(file_get_contents($path), buildRegistry());
}
```

No separate validation step is needed here — the registry
`loadConfig()` builds already has `Server`'s schema compiled into it,
so `decode()` itself enforces every field, automatically, as part of
decoding (§6). A malformed config file throws an ordinary `DXNError`
from `loadConfig()` directly, not something the caller has to
separately check for.

```
# config.dxn
%Server{
  host: "localhost"
  port: 4000
  tags: [dev local]
}
```

```php
loadConfig('config.dxn');
// => [
//      'host' => 'localhost',
//      'port' => 4000,
//      'tags' => DXNList{items: [DXNSymbol{name: 'dev'}, DXNSymbol{name: 'local'}]},
//    ]
```

`tags` decodes as a `DXNList`, not a bare PHP `array` — DXN's `list`
(`[ ... ]`) and `array` (`@array[ ... ]`) are distinct types, and this
port keeps them distinct too: `array` claims the bare PHP array shape
(the collections decision covered in the cheatsheet), so `list` gets
this thin wrapper instead. Call `->toArray()` on it to get a plain PHP
array back.

From here: [Examples](EXAMPLES.md) for more worked scenarios, the
[Cheatsheet](CHEATSHEET.md) for quick lookups, and the
[DXN reference](dxn/DXN.md) for the full format specification.
