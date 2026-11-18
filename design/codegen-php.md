# PHP code from DXNS (T685)

Status: first round built 2026-11-18 (`JOetjen\Dextrin\Codegen\Php`,
`bin/dextrin gen-php`); see "What the first round does" at the end.

The PHP counterpart of `mix dextrin.gen.elixir` (T684, dextrin's
design/codegen-elixir.md). What Jan settled there holds here unless a
question below says otherwise:

- generated code reads and checks data **with code of its own**: no
  `.dxns` file and no compiled schema is loaded at run time;
- **every known version** of a schema: one reader and one checker per
  version, a reader dispatching on the version written, and generated
  upgrades to the latest, with hand-written ones where a change can't
  be upgraded mechanically (a renamed field moves by itself, from
  `typespec.renamed-from` in `meta`);
- **what is written by hand is kept**: two files per schema, and marked
  regions inside the generated one; module (here: class) names are
  kept as the user spells them, found by a `dextrin:schema` id;
- **deprecation** marks only the deprecated version's methods, and
  data going through anything deprecated warns as the reader does
  (T698): `deprecations:` `once` / `every` / `off` / `error`, an
  `onDeprecated` callback, else `trigger_error($text,
  E_USER_DEPRECATED)` -- php-dextrin's own default;
- the decoder entry point is generated (`Decoder::decode`), not a
  registry, since a registry would need the schema at run time;
- the first round's scope is the Elixir one, the same forms left out
  (typed extras, `{:case}`, `{:list-items}`, tuples, sets, ordered
  maps, arrays, tagged values, hand-written generics, the refine keys
  `contains`, `content`, `encodes`, `encodes-seq`); each comes as a task
  when T690 needs it.

## What it writes

For `bin/dextrin gen-php schemas/ --namespace Asco\Schemas --out src/Schemas/`:

| DXNS | PHP |
|---|---|
| `acme.shop/Order`, a `%schema` | `Asco\Schemas\Acme\Shop\Order` (the user's, `Order.php`) extending `AbstractOrder` (the generator's, `AbstractOrder.php`), both PSR-4 under `--out` |
| its latest version's fields | `public readonly` typed properties, a constructor taking them by name, `@var` types phpstan level 8 accepts |
| each version N | `readV{N}(array $fields): static` and `conformV{N}(array $fields): array`, every check inlined |
| the versions together | `read(DXNStruct\|self $value, string $deprecations = 'once', ?callable $onDeprecated = null): static`, `validate(...)` with the same options, `toDxn(): DXNStruct` |
| a `%shape` | no class: a value stays the `DXNMap` it decoded to, checked by a generated private method |
| a named type | a private check method in each class that uses it |
| a hand-written upgrade | an `abstract protected function upgradeV1(array $fields): array` in `AbstractOrder`, so `Order` fails to load until it has one -- PHP's own fatal error, stronger than Elixir's warning |
| a deprecated version | `#[\Deprecated]` (PHP 8.4; ignored before) and `@deprecated` on its methods |
| everything | `Asco\Schemas\Decoder::decode(string $text, ...)`, taking `Dextrin::decode`'s parameters |

Failures throw `JOetjen\Dextrin\Codegen\InvalidData` (a `DXNError`, with
the field path), as php-dextrin's reader throws, rather than returning a
result.

Regions are `// dextrin:keep NAME` ... `// dextrin:end NAME`, with
slots `top`, `bottom` and one per upgrade step; a region whose slot is
gone becomes `orphaned_NAME` and fails `--check`. Both files start with
`// dextrin:schema acme.shop/Order`.

## Questions for Jan

1. **Two files as a class and its generated parent**: the user's
   `Order extends AbstractOrder`, `AbstractOrder` abstract and rewritten, rather
   than a generated trait the user's class `use`s? Recommended: the
   parent class -- an abstract method makes a missing hand-written upgrade
   a load-time error, and phpstan follows inheritance more exactly than
   traits.
2. **The value class**: `readonly` properties, built by a constructor
   with named arguments, changed by `with...()` copies? Recommended: yes
   -- immutable, as the decoded values are.
3. **Errors**: a refused value throws (`InvalidData`, a `DXNError`
   naming the field), as `Dextrin::decode` throws, rather than an
   `ok`/`error` result like the Elixir code's? Recommended: throw -- it is
   how the PHP library already reports.
4. **The command**: `bin/dextrin gen-php SCHEMAS --namespace NS --out DIR
   [--check]`, beside `from-json-schema` and `docs`? Recommended: yes.
5. **Generated code passes phpstan level 8**, checked by the test suite
   (the generated fixture is analysed in `composer run precommit`)?
   Recommended: yes -- users run phpstan on their own `src/`.
6. **Options**: an options array (`['deprecations' => 'once',
   'onDeprecated' => fn]`) on `read`, `validate` and `Decoder::decode`,
   rather than named parameters? Recommended: named parameters
   (`deprecations: 'once', onDeprecated: ...`), as `Dextrin::decode`
   already takes them.

## Decisions (Jan, 2026-11-18)

- Q1, Jan: "Wouldn't make `Order extends AbstractOrder` more sense and be
  more PHP-like?" -- yes: the generated abstract parent is
  `Abstract<Class>` in `Abstract<Class>.php`, beside the user's class,
  in its namespace. It follows the user's class name as the user spells
  it: renaming `Order` to `Purchase` in the user's file makes the parent
  `AbstractPurchase` on the next run. A schema whose class would be
  named like another schema's parent (`acme.shop/AbstractOrder` beside
  `acme.shop/Order`) is refused, naming both.
- Q2-Q6: as recommended -- readonly with `with...()` copies; refusal
  throws `InvalidData`; `bin/dextrin gen-php`; generated code passes
  phpstan level 8, checked by the suite; named-parameter options.

## What the first round does (2026-11-18)

Built as above, agreeing with the Elixir round as fixed on 2026-11-18:
refusals named as DXNS writes the form, computed defaults computed when
read, no check for a `:never` field, namespace directories normalized;
an added field's default filled in the upgrade step; the upgrade chain
stepping to the next existing version, so versions 1 and 3 with no 2
still upgrade; an orphan's note written once, not again on every run;
typed extras on a schema itself (`* K => V` in a `%schema` body) refused
like those on a shape; and a closed schema (`- *`) enforced -- before any
field is checked, a version refuses a field it does not declare, `field
"b" is not declared, and the schema takes no other`
(`schema/undeclared-field`, `Runtime::closed()`).

These differences remain, found while building:

- **Reading is static, and so is the hand-written upgrade.**
  `Order::read()`, `readVN()`, `conformVN()` and `upgradeFromVN()` are
  static: no object exists before a struct is read. The hand-written
  step is therefore `abstract protected static function upgradeVN(array
  $fields): array`, not an instance method; it is still abstract, so
  `Order` does not load without it.
- **The generated step is `upgradeFromVN()`**, the hand-written one
  `upgradeVN()`: in Elixir both are `upgrade_vN`, in two modules; here
  they share one class hierarchy. The region slot keeps the name
  `upgrade_vN`. It runs before `upgradeVN()` and may prepare the fields
  for it; it does not replace it.
- **`validate()` is an instance method**, `$order->validate()`, and
  `read()` given an object of the class validates it and answers it
  (Elixir's `read/1` answers its own struct unchecked): a reference field
  holding an object is checked that way.
- **`#[\Deprecated]` warns at every call in PHP 8.4**, not only at
  compile time as Elixir's `@deprecated`. `read()` therefore dispatches
  through private `fieldsVN()` methods and never calls the deprecated
  `readVN()`/`conformVN()`; only a direct call raises PHP's own
  deprecation, as intended.
- **Orphaned regions go after `__halt_compiler();`** at the end of the
  file, not at the end of the class: lines from a method body are a parse
  error in a class body, and the file must keep loading until the
  orphan is moved. `--check` fails on them as before.
- **Checks are generated private methods** (`t1()`, ...; a recursive
  named type's is named after it), each one call into
  `Codegen\Runtime`, one helper per form (`shape`, `listOf`, `anyOf`,
  `refine`, ...), rather than every check written out inline. Still no
  schema is loaded; constants (defaults, enum literals, refine
  constraints) are DXN text decoded once, in a static cache of the class.
- **Property names**: a field name PHP can't take as an identifier is
  camel-cased (`first-name` is `$firstName`); the struct keeps the DXN
  name. A copy method is camel-cased whatever the property is
  (`signed_up_at` has `withSignedUpAt()`), as PHP names methods. Constructor parameters without a default come first; a default
  PHP can write as a constant expression (a scalar, a keyword) is the
  parameter's default, any other makes the parameter required. The
  class carries `@phpstan-consistent-constructor`.
- **Property types**: native where exact (`string`, `bool`,
  `int|DXNInteger`, the `DXN*` class, the generated class for a
  reference, `DXNList` for a list, `array|DXNMap` with
  `array<int|string, mixed>` for a map or shape, a union for an enum of
  one kind or a union of exact variants, `?T` for an optional field,
  `null` for a `:never` one); `mixed` where none is (`{:all-of}`,
  `{:not}`, `:any`, a recursive named type).
- **"Once" is kept in `Codegen\Runtime`, by generated class**: the same
  scope as the design says, without a static property per class.
- **Namespaces and directories follow PSR-4**: each DXNS namespace part
  is a StudlyCase PHP namespace and the directory of the same name
  (`acme.shop.Order/OrderLine` is `Acme\Shop\Order\OrderLine` in
  `Acme/Shop/Order/OrderLine.php`), whatever case it was written in;
  Elixir's lower-case directories would break PSR-4 autoloading.
- **A renamed class moves its parent**: renaming `Order` to `Purchase`
  writes `AbstractPurchase.php` and removes `AbstractOrder.php`; `--check`
  names the leftover until then. Two files of one kind claiming one
  schema, and a schema whose class would be the generated `Decoder`, are
  refused like the `AbstractOrder` clash, naming both.
- **`Decoder::decode()`** passes `$registry` and `$strict` on to
  `Dextrin::decode()`; `$deprecations` and `$onDeprecated` govern the
  generated code only.
- **`DXNError` is no longer `final`**, so `InvalidData` can be one.
  `InvalidData::$path` holds the field, outermost first; the message
  writes it `customer.code: ...`, `lines[0]: ...`.
- **`TypeExpr::isPrimitive()`, `isEnumMember()`, `meetsConstraints()`**:
  the narrow public entry points the generated code calls, as Elixir made
  `primitive?/2`, `enum_member?/2` and `constraints?/3` public.
- **Not generated yet** (refused, naming schema, version and field):
  typed extras, `{:case}`, `{:list-items}`, tuples, sets, ordered maps,
  arrays, tagged values, generics written by hand, references pinned to a
  version, and the refine keys `contains`, `content`, `encodes`,
  `encodes-seq` -- the Elixir round's list.

The generated fixture, tests/Fixtures/Codegen (schemas and output, with
the one hand-written `upgradeV1()` in `Pet`), is regenerated by
`bin/dextrin gen-php tests/Fixtures/Codegen/schemas --namespace
'JOetjen\Dextrin\Tests\Fixtures\Codegen\Generated' --out
tests/Fixtures/Codegen/Generated`; the suite fails when it is stale, and
runs phpstan at level 8 on it.
