# Examples

Worked examples across a range of use cases. See the
[tutorial](TUTORIAL.md) first if you haven't already, and the
[DXN examples](dxn/DXN_EXAMPLES.md) for format-level (not
PHP-API-level) examples.

## A config file

```
# config.dxn
@dxn "1.0"
%{
  env:      :production
  debug:    false
  db: %{
    host:     "db.internal"
    port:     5432
    pool:     10
    timeout:  ~T[00:00:30]
  }
  features: @{:billing :notifications}
}
```

```php
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Value\DXNKeyword;

$config = Dextrin::decode(file_get_contents('config.dxn'));
$config['db']['host'];
// => 'db.internal'
in_array(new DXNKeyword('billing'), $config['features']->toArray());
// => true
```

A `map` (`%{ ... }`) decodes as a bare, native PHP `array` — every
key, including the shorthand `db:`/`features:` keywords, reduces to
its bare name string (this port's own documented map-key-coercion
rule), which is what makes `$config['db']['host']` work directly
above. A `set` (`@{ ... }`) decodes as `DXNSet` rather than a native
PHP structure (PHP has no built-in set type) — `in_array()` with
PHP's own default loose object comparison works for membership checks
since two `DXNKeyword`s with the same name compare `==` equal.

## An API payload with an explicit schema

```php
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\Std;

$schemaSource = <<<'DXN'
    %{
      User: %schema{
        id:       :uuid
        email:    NonEmptyString
        role:     {:enum :admin :member :guest}
        created:  :timestamp
        note?:    :string
      }
    }
    DXN;

$doc = Dextrin::decode($schemaSource);
$registry = Schema::compile($doc, Std::registry());

$payload = <<<'DXN'
    %User{
      id:      @uuid "550e8400-e29b-41d4-a716-446655440000"
      email:   "ada@example.com"
      role:    :admin
      created: ~U[2024-01-01 00:00:00Z]
    }
    DXN;

Dextrin::decode($payload, $registry);
// => [
//      'id' => DXNUuid{...}, 'email' => 'ada@example.com',
//      'role' => DXNKeyword{name: 'admin'}, 'created' => DXNTimestamp{...},
//      'note' => null,
//    ]
```

An optional field with no value supplied (`note?:`) materializes as
`null`, not simply omitted from the result — the key is always
present, matching how every other decoded field is looked up.

## An event log, streamed to `.dxnb` for storage

```php
$events = [
    Dextrin::decode('%{type: :login, user_id: 1, at: ~U[2024-01-01 08:00:00Z]}'),
    Dextrin::decode('%{type: :purchase, user_id: 1, amount: 19.99M, at: ~U[2024-01-01 08:05:00Z]}'),
];

$encoded = array_map(fn (mixed $event): string => Dextrin::encodeBinary($event), $events);
// ... write each to a file/stream, one .dxnb value per record ...
$decoded = array_map(fn (string $bytes): mixed => Dextrin::decodeBinary($bytes), $encoded);
```

`.dxnb` is a good fit here specifically because `timestamp` uses tag
1's *integer* microsecond form (never the lossy float form) and
`decimal` maps directly to CBOR tag 4 — money and event timestamps
round-trip exactly, unlike JSON-over-the-wire where both would need
an app-level convention.

## Money: `struct` + `refine`, combined

```php
use JOetjen\Dextrin\Registry;

$schemaSource = <<<'DXN'
    %{
      Money: %schema{
        amount:   :decimal
        currency: {:enum :usd :eur :gbp}
      }
    }
    DXN;

$doc = Dextrin::decode($schemaSource);
$registry = Schema::compile($doc, new Registry());
$registry = $registry->putStructMaterializer('Money', fn (array $f): array => [
    'amount' => $f['amount'],
    'currency' => $f['currency']->name,
]);

Dextrin::decode('%Money{amount: 19.99M, currency: :usd}', $registry);
// => ['amount' => DXNDecimal{sign: 1, unscaledValue: '1999', exponent: -2}, 'currency' => 'usd']
```

`currency` decodes as a `DXNKeyword` — `->name` reads the plain string
back out, this port's equivalent of Elixir's `Atom.to_string/1` (PHP
has no atom/interned-symbol type for `keyword` to decode as instead,
see the tutorial's own note on this).

## A schema, version by version

Each `.dxns` document describes one version of a schema, in its
header; the body is the fields, in order. Without a header it is
version 1:

```php
$registry = Schema::compile(Dextrin::decode('%{ User: %schema{ name: :string } }'), new Registry());
$registry = Schema::compile(Dextrin::decode('%{ User: %schema(2){ name: :string, email?: :string } }'), $registry);

$registry->fetchStructSchema('User')->compiled->version;          // => 2, the latest
$registry->fetchStructSchema('User', 1)->compiled->fieldNames();  // => ['name']

Schema::compile(Dextrin::decode('%{ User: %schema(2){ name: :string } }'), $registry);
// => throws DXNError, category 'schema/duplicate-version'
```

Loading the same version twice is a mistake rather than an update;
`$registry->replaceStructSchema($name, $compiled)` swaps a loaded
version on purpose. A struct can say which version it was written
with, and is written back with it:

```php
Dextrin::decode('%User(2){name: "Ada"}')->version;                // => 2
Dextrin::encode(Dextrin::decode('%User(2){name: "Ada"}'));        // => '%User(2){name:"Ada"}'
```

A version must be a positive integer: `%User(0){...}` is a syntax error.

## A third-party class provider, end to end

`DXNSchemaProvider` lets a class's *own* library define its DXN schema
without depending on `dextrin` — see
[`src/Schema/Provider.php`](src/Schema/Provider.php)'s own doc for the
complete rationale. This example shows both sides: the geo library
that owns the class, and the application that uses it.

The library, `geo-lib`, ships a `LatLng` class. It doesn't depend on
`dextrin` itself at all — the provider class below only needs the two
small interfaces to implement, nothing else from this package:

```php
// geo-lib/src/LatLng.php -- ships in the geo library itself
use JOetjen\Dextrin\Schema\DXNSchemaMaterializingProvider;

final class LatLng
{
    public function __construct(public float $lat, public float $lng) {}
}

// Defines a shared named type (Degrees) alongside the one schema this
// class is actually "for" -- both get compiled into the registry
// together.
final class LatLngProvider implements DXNSchemaMaterializingProvider
{
    public function dxnSchema(): string
    {
        return <<<'DXN'
            %{
              Degrees: {:refine :float %{min: -180.0, max: 180.0}}
              LatLng: %schema{ lat: Degrees, lng: Degrees }
            }
            DXN;
    }

    public function dxnSchemaName(): string { return 'LatLng'; }

    public function dxnClass(): string { return LatLng::class; }

    public function dxnMaterialize(array $resolved): LatLng
    {
        return new LatLng($resolved['lat'], $resolved['lng']);
    }
}
```

The application depends on both `geo-lib` and `dextrin`. It composes
`geo-lib`'s provider with this project's own standard named types in
one registry, exactly like chaining any other base registry:

```php
$registry = Schema::registerProvider(Std::registry(), new LatLngProvider());

Dextrin::decode('%LatLng{lat: 51.05, lng: 13.74}', $registry);
// => LatLng{lat: 51.05, lng: 13.74}

Dextrin::encode(new LatLng(51.05, 13.74), registry: $registry);
// => '%LatLng{lat:51.05,lng:13.74}'

// Degrees' own refine constraint is enforced automatically, same as
// any other schema -- no special handling needed on either side:
Dextrin::decode('%LatLng{lat: 200.0, lng: 13.74}', $registry);
// => throws DXNError: struct "LatLng" violates its schema: field "lat" does not match its declared type
```

`geo-lib` never mentions `Registry`, `Schema`, or any other `dextrin`
class outside `LatLng.php`/`LatLngProvider.php` — an application that
uses `LatLng` without `dextrin` installed runs exactly as if those two
classes were never loaded.

## Cross-file schemas with `FileResolver`

Given `schemas/Address.dxns` and `schemas/Person.dxns` on disk:

```
# schemas/Address.dxns
%{
  Address: %schema{ street: :string }
}

# schemas/Person.dxns
%{
  Person: %schema{ name: :string, home: Address }
}
```

```php
use JOetjen\Dextrin\Schema\FileResolver;

$doc = Dextrin::decode(file_get_contents('schemas/Person.dxns'));
$resolver = FileResolver::forPaths(['schemas']);
$registry = Schema::compile($doc, (new Registry())->putResolver($resolver));

Dextrin::decode('%Person{name: "Ada", home: %Address{street: "1 Main St"}}', $registry);
// => ['name' => 'Ada', 'home' => ['street' => '1 Main St']]
```

`Address.dxns` is loaded and compiled lazily, on first encounter with
its name, and memoized in the registry for the rest of that decode.
`FileResolver` also understands a namespaced `Namespace/Name` field
reference (resolving to `<searchPath>/Namespace.dxns`'s `Name` entry,
for a file that defines a differently-named schema than its own file
stem) — but keep the *struct literal itself* written under its own
bare schema name (`%Address{...}`, never `%Namespace/Name{...}`),
since a reference's declared name is checked against the referenced
value's own compiled schema name exactly, and that name is always the
bare entry name a `.dxns` file defines it under.
