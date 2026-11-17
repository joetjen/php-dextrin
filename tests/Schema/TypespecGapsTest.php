<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

/**
 * What DXNS lacked to say every TypeSpec type exactly (design/typespec.md
 * in dextrin, as its typespec_gaps_test.exs):
 *
 * - G2: generic parameters with a bound and a default;
 * - G3: the `discriminator` annotation;
 * - G5: the annotation keys `secret`, `key`, `visibility` and `messages`;
 * - G6: a base that is a named type standing for a schema or a shape;
 * - G7: the format `http-date`;
 * - G8: a field computed when it is filled (`computed: :now`);
 * - G9: the sized sugar `:int8`, `:int16`, `:uint8` and `:uint16`.
 *
 * G1 (typed and closed extras) is in RestTest, G10 (deprecation
 * warnings) in DeprecationTest.
 */
final class TypespecGapsTest extends TestCase
{
    private static function compiled(string $text, ?Registry $registry = null): Registry
    {
        return Schema::compile(Dextrin::decode($text), $registry ?? new Registry());
    }

    /** The message `$text` is refused with, a `schema/compile` error. */
    private static function compileError(string $text, ?Registry $registry = null): string
    {
        try {
            self::compiled($text, $registry);
        } catch (\Throwable $err) {
            self::assertSame('schema/compile', self::categoryOf($err), $err->getMessage());
            return $err->getMessage();
        }
        self::fail("{$text} should be refused");
    }

    private static function categoryOf(\Throwable $err): ?string
    {
        return match (true) {
            $err instanceof HasCategory => $err->category(),
            $err instanceof DXNError => $err->category,
            default => null,
        };
    }

    /** The category `$run` fails with, or `ok` when it does not. */
    private static function category(callable $run): string
    {
        try {
            $run();
        } catch (\Throwable $err) {
            return self::categoryOf($err) ?? $err::class;
        }
        return 'ok';
    }

    /** Whether the definition `$name` takes the value `$text` decodes to. */
    private static function takes(Registry $registry, string $name, string $text): bool
    {
        return self::category(static fn () => Schema::check(Dextrin::decode($text), $registry, $name)) === 'ok';
    }

    /** The message `$value` is refused with by `$name`. */
    private static function refusal(mixed $value, Registry $registry, string $name): string
    {
        try {
            Schema::check($value, $registry, $name);
        } catch (\Throwable $err) {
            self::assertSame('schema/type-mismatch', self::categoryOf($err), $err->getMessage());
            return $err->getMessage();
        }
        self::fail("{$name} should refuse the value");
    }

    // ---- G3: a discriminated union over schemas -------------------------------

    public function testG3ADiscriminatedUnionOverSchemasExplainsByTheChosenSchema(): void
    {
        $r = self::compiled(<<<'DXN'
            %{
              Cat: %schema{kind: {:const "cat"}, meows: :boolean}
              Dog: %schema{kind: {:const "dog"}, barks: :boolean}
              Cow: %schema{kind: :string}
              Home: %schema{pet: {:doc {:one-of Cat Dog} %{discriminator: :kind}}}
            }
            DXN);
        try {
            Dextrin::decode('%Home{pet: %Cow{kind: "cat"}}', $r);
            self::fail('expected a refusal');
        } catch (DXNError $err) {
            self::assertStringContainsString('kind "cat" chooses Cat', $err->getMessage());
        }
    }

    // ---- G2: generic parameters ------------------------------------------------

    private const GENERICS = <<<'DXN'
        %{
          Page: {:generic [t {size :integer :integer}] %shape{items: {:list-of t}, size?: size}}
          Named: {:generic [{t :string}] %shape{name: t}}
          Short: {:refine :string %{max-length: 3}}
          Person: %schema{name: :string}
          Admin: %schema extends Person {role: :string}
          Holder: {:generic [{p Person}] %shape{who: p}}
        }
        DXN;

    public function testG2AParameterWithADefaultMayBeLeftOutOfAUse(): void
    {
        $r = self::compiled('%{Orders: {:of Page :string}}', self::compiled(self::GENERICS));
        self::assertTrue(self::takes($r, 'Orders', '%{items: ["a"], size: 1}'));
        self::assertFalse(self::takes($r, 'Orders', '%{items: ["a"], size: "1"}'));
    }

    public function testG2EveryParameterWithoutADefaultMustBeGiven(): void
    {
        $r = self::compiled(self::GENERICS);
        self::assertSame('schema/compile', self::category(static fn () => self::compiled('%{X: {:of Page}}', $r)));
    }

    public function testG2AParameterWithoutADefaultAfterOneWithADefaultIsACompileError(): void
    {
        self::assertStringContainsString('default', self::compileError('%{G: {:generic [{a :any :integer} b] {:tuple-of a b}}}'));
    }

    public function testG2AnArgumentPassesItsParametersBoundWhenItIsOrNarrowsTheBound(): void
    {
        $r = self::compiled(self::GENERICS);
        foreach ([':string', 'Short', '{:refine :string %{min-length: 1}}', '{:const "a"}', '{:enum "a" "b"}'] as $arg) {
            self::assertSame('ok', self::category(static fn () => self::compiled("%{X: {:of Named {$arg}}}", $r)), $arg);
        }
        self::assertSame('ok', self::category(static fn () => self::compiled('%{X: {:of Holder Admin}}', $r)));
    }

    public function testG2AnArgumentOutsideTheBoundIsACompileError(): void
    {
        $r = self::compiled(self::GENERICS);
        foreach ([':integer', '{:enum "a" 1}', '{:any-of :string :integer}'] as $arg) {
            self::assertSame('schema/compile', self::category(static fn () => self::compiled("%{X: {:of Named {$arg}}}", $r)), $arg);
        }
        self::assertSame(
            'schema/compile',
            self::category(static fn () => self::compiled('%{X: {:of Holder Person2}, Person2: %schema{name: :string}}', $r)),
        );
    }

    public function testG2AParameterPassesOnToABoundedGenericWhenItsOwnBoundIsWithin(): void
    {
        $r = self::compiled(self::GENERICS);
        self::assertSame('ok', self::category(static fn () => self::compiled('%{Outer: {:generic [{u :string}] {:of Named u}}}', $r)));
        self::assertSame('schema/compile', self::category(static fn () => self::compiled('%{Outer: {:generic [u] {:of Named u}}}', $r)));
        self::assertSame('schema/compile', self::category(static fn () => self::compiled('%{Outer: {:generic [{u :integer}] {:of Named u}}}', $r)));
    }

    public function testG2AParameterPassedOnWithinOneDocumentIsCheckedWhenTheDocumentResolves(): void
    {
        $generics = '%{Named: {:generic [{t :string}] %shape{name: t}}, ';
        self::assertSame('ok', self::category(static fn () => self::compiled($generics . 'Outer: {:generic [{u :string}] {:of Named u}}}')));
        self::assertSame('schema/compile', self::category(static fn () => self::compiled($generics . 'Outer: {:generic [u] {:of Named u}}}')));
    }

    public function testG2AnArgumentOrABoundMayBeATypeOfTheSameDocument(): void
    {
        $r = self::compiled(self::GENERICS);
        self::assertSame('ok', self::category(static fn () => self::compiled('%{Tiny: {:refine :string %{max-length: 1}}, X: {:of Named Tiny}}', $r)));
        self::assertSame('ok', self::category(static fn () => self::compiled('%{Str: :string, G: {:generic [{t Str}] t}, X: {:of G :string}}')));
        self::assertSame(
            'schema/compile',
            self::category(static fn () => self::compiled('%{Str: :string, G: {:generic [{t Str}] t}, X: {:of G :integer}}')),
        );
        self::assertSame(
            'schema/compile',
            self::category(static fn () => self::compiled('%{Num: :integer, X: {:of Named Num}}', $r)),
        );
    }

    public function testG2AUseAsABaseHasItsDefaultsFilled(): void
    {
        $r = self::compiled(<<<'DXN'
            %{
              Page: {:generic [t {size :integer :integer}] %shape{items: {:list-of t}, size?: size}}
              P: {:of Page :string}
              S: %schema includes P {}
            }
            DXN);
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%S{items: ["a"], size: 1}', $r)));
        self::assertSame('schema/type-mismatch', self::category(static fn () => Dextrin::decode('%S{items: ["a"], size: "1"}', $r)));
    }

    public function testG2ARefusalThroughAUseOfAGenericSaysWhy(): void
    {
        $r = self::compiled('%{Orders: {:of Page :string}}', self::compiled(self::GENERICS));
        $message = self::refusal(Dextrin::decode('%{items: [1]}'), $r, 'Orders');
        self::assertStringContainsString('items', $message);
        self::assertSame('value does not match "Orders": field "items" does not match its type', $message);
    }

    public function testG2ADefaultMustPassItsOwnBound(): void
    {
        self::assertStringContainsString('bound', self::compileError('%{G: {:generic [{t :string :integer}] t}}'));
    }

    public function testG2AParameterIsASymbolANameAndBoundOrANameBoundAndDefault(): void
    {
        self::assertSame('schema/compile', self::category(static fn () => self::compiled('%{G: {:generic [{t}] t}}')));
        self::assertSame('schema/compile', self::category(static fn () => self::compiled('%{G: {:generic [{t :any :any :any}] t}}')));
        self::assertSame('schema/compile', self::category(static fn () => self::compiled('%{G: {:generic [1] :any}}')));
    }

    // ---- G3: discriminator -----------------------------------------------------

    private const PETS = <<<'DXN'
        %{
          Cat: %shape{kind: {:const "cat"}, meows: :boolean}
          Dog: %shape{kind: {:const "dog"}, barks: :boolean}
          Pet: {:doc {:one-of Cat Dog} %{discriminator: :kind}}
        }
        DXN;

    public function testG3ADiscriminatorChangesNothingAValueIsCheckedAgainst(): void
    {
        $r = self::compiled(self::PETS);
        self::assertTrue(self::takes($r, 'Pet', '%{kind: "cat", meows: true}'));
        self::assertFalse(self::takes($r, 'Pet', '%{kind: "cat", barks: true}'));
        self::assertFalse(self::takes($r, 'Pet', '%{kind: "cow"}'));
    }

    public function testG3ADiscriminatorIsReadBackAsAnAnnotation(): void
    {
        self::assertSame(['discriminator' => 'kind'], Schema::annotations(self::compiled(self::PETS), 'Pet'));
    }

    public function testG3ARefusalNamesTheFieldAndTheVariantItChose(): void
    {
        $r = self::compiled(self::PETS);
        $unknown = self::refusal(Dextrin::decode('%{kind: "cow"}'), $r, 'Pet');
        self::assertStringContainsString('kind', $unknown);
        self::assertStringContainsString('cow', $unknown);
        self::assertSame('value does not match "Pet": kind "cow" names no variant', $unknown);

        $wrong = self::refusal(Dextrin::decode('%{kind: "cat", meows: 1}'), $r, 'Pet');
        self::assertStringContainsString('meows', $wrong);
        self::assertStringNotContainsString('barks', $wrong);
        self::assertSame(
            'value does not match "Pet": kind "cat" chooses Cat: field "meows" does not match its type',
            $wrong,
        );
    }

    public function testG3EveryVariantHasTheFieldWrittenInAFieldsTypeToo(): void
    {
        $shape = self::compileError(<<<'DXN'
            %{
              Cat: %shape{kind: {:const "cat"}}
              Bird: %shape{wings: :integer}
              Home: %shape{pet: {:doc {:one-of Cat Bird} %{discriminator: :kind}}}
            }
            DXN);
        self::assertStringContainsString('kind', $shape);
        self::assertSame('"Home": its discriminator kind is no field of Bird', $shape);
        $schema = self::compileError(<<<'DXN'
            %{
              Cat: %shape{kind: {:const "cat"}}
              Bird: %shape{wings: :integer}
              Home: %schema{pet: {:doc {:one-of Cat Bird} %{discriminator: :kind}}}
            }
            DXN);
        self::assertStringContainsString('kind', $schema);
        // A schema's field types have their named types in place, so the
        // variant is told as the reference tells it: by no name.
        self::assertSame('"Home": its discriminator kind is no field of an inline variant', $schema);
    }

    public function testG3ADiscriminatorStandsOnlyOnAnAnyOfOrAOneOfWhoseEveryVariantHasTheField(): void
    {
        self::assertStringContainsString('discriminator', self::compileError('%{T: {:doc :string %{discriminator: :kind}}}'));
        self::assertStringContainsString('kind', self::compileError(<<<'DXN'
            %{
              Cat: %shape{kind: {:const "cat"}}
              Bird: %shape{wings: :integer}
              Pet: {:doc {:any-of Cat Bird} %{discriminator: :kind}}
            }
            DXN));
        self::assertStringContainsString(
            'discriminator',
            self::compileError('%{Cat: %shape{kind: :string}, P: {:doc {:one-of Cat} %{discriminator: 1}}}'),
        );
    }

    // ---- G5: annotation keys ---------------------------------------------------

    public function testG5SecretKeyVisibilityAndMessagesAreReadBack(): void
    {
        $r = self::compiled(<<<'DXN'
            %{
              User: %schema{
                id: {:doc :uuid %{key: true, visibility: [:read]}}
                password: {:doc :string %{secret: true, visibility: [:create :update]}}
                handle: {:doc :string %{key: "login"}}
              }
              Code: {:doc {:refine :string %{pattern: @regex "^[a-z]+$"}} %{messages: %{pattern: "lower case only"}}}
              Login: {:doc :string %{key: "login", secret: false, visibility: [:create :read]}}
            }
            DXN);
        self::assertSame(['messages' => ['pattern' => 'lower case only']], Schema::annotations($r, 'Code'));
        self::assertEquals(
            ['key' => 'login', 'secret' => false, 'visibility' => new DXNList(['create', 'read'])],
            Schema::annotations($r, 'Login'),
        );
    }

    public function testG5AMessageReplacesTheRefusalsOwnWords(): void
    {
        $r = self::compiled('%{Code: {:doc {:refine :string %{pattern: @regex "^[a-z]+$"}} %{messages: %{pattern: "lower case only"}}}}');
        self::assertStringContainsString('lower case only', self::refusal('ABC', $r, 'Code'));
    }

    public function testG5EachKeyTakesItsKindOfValue(): void
    {
        self::assertStringContainsString('secret', self::compileError('%{T: {:doc :string %{secret: 1}}}'));
        self::assertStringContainsString('key', self::compileError('%{T: {:doc :string %{key: 1}}}'));
        self::assertStringContainsString('visibility', self::compileError('%{T: {:doc :string %{visibility: [:sometimes]}}}'));
        self::assertStringContainsString('visibility', self::compileError('%{T: {:doc :string %{visibility: :read}}}'));
        self::assertStringContainsString('min-lenght', self::compileError('%{T: {:doc :string %{messages: %{min-lenght: "x"}}}}'));
        self::assertStringContainsString('messages', self::compileError('%{T: {:doc :string %{messages: %{pattern: 1}}}}'));
    }

    public function testG5VisibilityAndReadOnlyTogetherAreACompileError(): void
    {
        self::assertStringContainsString('read-only', self::compileError('%{T: {:doc :string %{read-only: true, visibility: [:read]}}}'));
    }

    public function testG5AnEmptyVisibilityIsAFieldNoStageSees(): void
    {
        self::assertSame('ok', self::category(static fn () => self::compiled('%{T: {:doc :string %{visibility: []}}}')));
    }

    // ---- the explanation on failure ------------------------------------------------

    public function testAFieldsRefusalSaysWhyWhereItCan(): void
    {
        $r = self::compiled(self::PETS . '');
        $r = self::compiled('%{Home: %schema{pet: Pet}}', $r);
        try {
            Dextrin::decode('%Home{pet: %{kind: "dog", barks: "loud"}}', $r);
            self::fail('the pet should be refused');
        } catch (DXNError $err) {
            self::assertSame('schema/type-mismatch', $err->category);
            self::assertStringContainsString(
                'field "pet" does not match its declared type: kind "dog" chooses Dog: field "barks" does not match its type',
                $err->getMessage(),
            );
        }
    }

    public function testAMissingFieldOfAShapeIsNamed(): void
    {
        $r = self::compiled('%{P: %shape{name: :string, age: :integer}}');
        self::assertSame('value does not match "P": field "age" is missing', self::refusal(Dextrin::decode('%{name: "a"}'), $r, 'P'));
    }

    // ---- G6: a named type as a base ------------------------------------------------

    private const NAMED = <<<'DXN'
        %{
          Page: {:generic [t] %shape{items: {:list-of t}, next?: :string}}
          PageOfInt: {:of Page :integer}
          Ints: %schema includes PageOfInt {total: :integer}

          Person: %schema{name: :string}
          Human: Person
          User: %schema extends Human {email: :string}
          Box: %schema{who: Person}
        }
        DXN;

    public function testG6AUseOfAGenericStandingForAShapeIsABase(): void
    {
        $r = self::compiled(self::NAMED);
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%Ints{items: [1], total: 1}', $r)));
        self::assertSame('schema/type-mismatch', self::category(static fn () => Dextrin::decode('%Ints{items: ["a"], total: 1}', $r)));
    }

    public function testG6ANameStandingForASchemaIsThatSchemaExtendingIncluded(): void
    {
        $r = self::compiled(self::NAMED);
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%Box{who: %User{name: "a", email: "b"}}', $r)));
    }

    public function testG6ANamedTypeStandingForAnythingElseIsStillNoBase(): void
    {
        self::assertStringContainsString('N', self::compileError('%{N: :integer, S: %schema includes N {}}'));
        self::assertStringContainsString('N', self::compileError('%{N: {:list-of :integer}, S: %schema includes N {}}'));
    }

    // ---- G7: format http-date ------------------------------------------------------

    private static function httpDate(string $text): bool
    {
        $r = self::compiled('%{T: {:refine :string %{format: :http-date}}}');
        return self::category(static fn () => Schema::check($text, $r, 'T')) === 'ok';
    }

    public function testG7TakesRfc7231sImfFixdate(): void
    {
        self::assertTrue(self::httpDate('Sun, 06 Nov 1994 08:49:37 GMT'));
        self::assertTrue(self::httpDate('Thu, 29 Feb 2024 23:59:60 GMT'));
    }

    public function testG7RefusesTheObsoleteFormsAWrongWeekdayAndADateThatIsNone(): void
    {
        foreach ([
            'Sunday, 06-Nov-94 08:49:37 GMT',
            'Sun Nov  6 08:49:37 1994',
            'Mon, 06 Nov 1994 08:49:37 GMT',
            'Sun, 6 Nov 1994 08:49:37 GMT',
            'Wed, 31 Nov 1994 08:49:37 GMT',
            'Thu, 29 Feb 2023 08:49:37 GMT',
            'Sun, 06 Nov 1994 24:00:00 GMT',
            'Sun, 06 Nov 1994 08:49:37 +0000',
            'Sun, 06 nov 1994 08:49:37 GMT',
            "Sun, 06 Nov 1994 08:49:37 GMT\n",
        ] as $text) {
            self::assertFalse(self::httpDate($text), $text);
        }
    }

    // ---- G8: computed: :now --------------------------------------------------------

    private const STAMPED = <<<'DXN'
        %{
          Note: %shape{text: :string, at: %field{type: :timestamp, computed: :now}}
          Day: %shape{on: %field{type: :date, computed: :now}}
        }
        DXN;

    public function testG8AMissingFieldIsFilledWithTheTimeOfTheDecode(): void
    {
        $r = self::compiled(self::STAMPED);
        $before = (new \DateTimeImmutable('now'))->getTimestamp();
        $conformed = Schema::conform(['text' => 'a'], $r, 'Note');
        self::assertIsArray($conformed);
        $at = $conformed['at'] ?? null;
        self::assertInstanceOf(DXNDateTime::class, $at);
        self::assertSame(0, $at->offsetMinutes);
        $seconds = intdiv($at->epochNanoseconds, 1_000_000_000);
        self::assertGreaterThanOrEqual($before, $seconds);
        self::assertLessThan(5, (new \DateTimeImmutable('now'))->getTimestamp() - $seconds);
    }

    public function testG8AFieldThatIsThereIsKept(): void
    {
        $value = Dextrin::decode('%{text: "a", at: @datetime "2020-01-01T00:00:00Z"}');
        self::assertEquals($value, Schema::conform($value, self::compiled(self::STAMPED), 'Note'));
    }

    public function testG8ADateIsTodaysInUtc(): void
    {
        $conformed = Schema::conform([], self::compiled(self::STAMPED), 'Day');
        self::assertIsArray($conformed);
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::assertEquals(
            new DXNDate((int) $today->format('Y'), (int) $today->format('n'), (int) $today->format('j')),
            $conformed['on'] ?? null,
        );
    }

    public function testG8AStructsMissingFieldIsFilledOnDecodeAndWhenDxnbFillsItsPosition(): void
    {
        $r = self::compiled('%{Stamp: %schema{n: :integer, at: %field{type: :timestamp, computed: :now}}}');
        $decoded = Dextrin::decode('%Stamp{n: 1}', $r);
        self::assertInstanceOf(DXNStruct::class, $decoded);
        self::assertInstanceOf(DXNDateTime::class, array_column($decoded->pairs(), 1, 0)['at'] ?? null);

        $read = Dextrin::decodeBinary(Dextrin::encodeBinary(DXNStruct::keyed('Stamp', [['n', 1]]), $r));
        self::assertInstanceOf(DXNStruct::class, $read);
        self::assertInstanceOf(DXNDateTime::class, $read->values()[1] ?? null);
    }

    public function testG8OnlyATemporalTypeIsComputedNeverBesideADefaultAndNowIsAllThereIs(): void
    {
        self::assertStringContainsString('computed', self::compileError('%{N: %shape{a: %field{type: :string, computed: :now}}}'));
        self::assertStringContainsString(
            'default',
            self::compileError('%{N: %shape{a: %field{type: :date, computed: :now, default: @date "2020-01-01"}}}'),
        );
        self::assertStringContainsString('later', self::compileError('%{N: %shape{a: %field{type: :date, computed: :later}}}'));
    }

    // ---- G9: small sized integers --------------------------------------------------

    public function testG9SmallSizedIntegersAreTheirRanges(): void
    {
        $cases = [
            [':int8', '-128', '127', '-129', '128'],
            [':int16', '-32768', '32767', '-32769', '32768'],
            [':uint8', '0', '255', '-1', '256'],
            [':uint16', '0', '65535', '-1', '65536'],
        ];
        foreach ($cases as [$type, $low, $high, $under, $over]) {
            $r = self::compiled("%{T: {$type}}");
            self::assertTrue(self::takes($r, 'T', $low), "{$type} {$low}");
            self::assertTrue(self::takes($r, 'T', $high), "{$type} {$high}");
            self::assertFalse(self::takes($r, 'T', $under), "{$type} {$under}");
            self::assertFalse(self::takes($r, 'T', $over), "{$type} {$over}");
        }
    }
}
