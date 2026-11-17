<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Document;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\Compiled;
use JOetjen\Dextrin\Schema\TypeExpr;
use JOetjen\Dextrin\Value\DXNStruct;
use PHPUnit\Framework\TestCase;

/**
 * A schema or a shape built from others (DXNS.md §4b, Jan, 2026-11-17):
 * `extends` and `includes` in its head, `- field` in its body. The result
 * is an ordinary schema or shape; an `extends` result is accepted where
 * its base is expected, when it is a valid base as well; an `includes`
 * result is not.
 */
final class ExtendsTest extends TestCase
{
    private const USERS = <<<'DXN'
        %{
          Person: %shape{name: :string, born?: :date}

          User: %schema(3) extends Person {
            email: :string
            password: :string
            nick?: :string
          }

          Audit: %shape{changed_by: :string}

          Admin: %schema(1) extends User includes Audit {
            - nick
            email: {:refine :string %{format: "email"}}
            role: :string
          }
        }
        DXN;

    private const V1 = '%{User: %schema(1){name: :string}}';
    private const V2 = '%{User: %schema(2){name: :string, email: :string}}';

    private static function compiled(string $text, ?Registry $registry = null): Registry
    {
        return Schema::compile(Dextrin::decode($text), $registry ?? new Registry());
    }

    private static function schema(Registry $registry, string $name): Compiled
    {
        $found = $registry->fetchStructSchema($name);
        self::assertNotNull($found);
        return $found->compiled;
    }

    /**
     * A schema's fields as `[name, type]`, an optional one's name ending
     * in `?`, a primitive by its name, a refinement as `refine:` and its
     * base.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function fields(Registry $registry, string $name): array
    {
        return array_map(
            static fn ($f): array => [$f->required ? $f->name : $f->name . '?', self::short($f->type)],
            self::schema($registry, $name)->fields,
        );
    }

    private static function short(TypeExpr $type): string
    {
        return match ($type->form) {
            'primitive' => (string) $type->name,
            'refine' => 'refine:' . self::short($type->base ?? TypeExpr::any()),
            default => $type->form,
        };
    }

    /** The category `$run` fails with, or `ok` when it does not. */
    private static function category(callable $run): string
    {
        try {
            $run();
        } catch (HasCategory $err) {
            return $err->category();
        } catch (\JOetjen\Dextrin\DXNError $err) {
            return (string) $err->category;
        }
        return 'ok';
    }

    // ---- the head and the body, read as text ---------------------------------

    public function testASchemasHeadCarriesItsBasesTheBodyItsDroppedFields(): void
    {
        $value = Dextrin::decode('%schema(2) extends User(3) Staff includes Audit {- password, role: :string}');
        self::assertInstanceOf(DXNStruct::class, $value);
        self::assertSame('schema', $value->name);
        self::assertSame(2, $value->version);
        self::assertSame([['extends', 'User', 3], ['extends', 'Staff', null], ['includes', 'Audit', null]], $value->bases);
        self::assertSame(['password'], $value->drops);
    }

    public function testAShapeTakesThemAsWell(): void
    {
        $value = Dextrin::decode('%shape includes Audit {-x}');
        self::assertInstanceOf(DXNStruct::class, $value);
        self::assertSame('shape', $value->name);
        self::assertSame([['includes', 'Audit', null]], $value->bases);
        self::assertSame(['x'], $value->drops);
    }

    public function testAnyOtherStructTakingAClauseOrADroppedFieldIsASyntaxError(): void
    {
        self::assertSame('syntax', self::category(static fn () => Dextrin::decode('%User extends Person {name: "a"}')));
        self::assertSame('syntax', self::category(static fn () => Dextrin::decode('%User{- name}')));
    }

    public function testAClauseGivenTwiceOrWithNoBaseIsASyntaxError(): void
    {
        foreach ([
            '%schema extends A extends B {}',
            '%schema extends {}',
            '%schema A {}',
            '%schema extends(2) A {}',
        ] as $text) {
            self::assertSame('syntax', self::category(static fn () => Dextrin::decode($text)), $text);
        }
    }

    public function testAMinusBeforeADigitStaysANumbersSign(): void
    {
        self::assertSame(-1, Dextrin::decode('-1'));
        self::assertSame([-1, 2], Dextrin::decode('@array[-1 2]'));
    }

    public function testWritesBackAsItWasRead(): void
    {
        $value = Dextrin::decode('%schema(2) extends User(3) Staff includes Audit {- password role: :string}');
        $written = Dextrin::encode($value);
        self::assertEquals($value, Dextrin::decode($written));
        self::assertStringContainsString('extends User(3) Staff includes Audit', $written);
        self::assertStringContainsString('- password', $written);
        self::assertSame(
            '%schema(2) extends User(3) Staff includes Audit {- password role: :string}',
            \JOetjen\Dextrin\Canonical::print($value),
        );
    }

    public function testIsWrittenBackOverLinesAndByTheFormatterThatKeepsComments(): void
    {
        $value = Dextrin::decode('%schema(2) extends User(3) includes Audit {- password, role: :string}');
        $pretty = Dextrin::encode($value, pretty: true);
        self::assertEquals($value, Dextrin::decode($pretty));

        $source = "%{\n  Admin: %schema extends User {\n    - password  # gone\n    role: :string\n  }\n}";
        self::assertSame($source, Document::parse($source)->format());
    }

    public function testHasNoDxnbForm(): void
    {
        $value = Dextrin::decode('%schema extends User {role: :string}');
        self::assertSame('encode/unsupported', self::category(static fn () => Dextrin::encodeBinary($value)));
    }

    // ---- the result ----------------------------------------------------------

    public function testIsTheBasesFieldsInOrderOverridesInPlaceDropsGoneItsOwnLast(): void
    {
        $registry = self::compiled(self::USERS);
        self::assertSame([
            ['name', 'string'],
            ['born?', 'date'],
            ['email', 'refine:string'],
            ['password', 'string'],
            ['changed_by', 'string'],
            ['role', 'string'],
        ], self::fields($registry, 'Admin'));
        self::assertSame(1, self::schema($registry, 'Admin')->version);
        self::assertSame([['extends', 'User', 3], ['includes', 'Audit', null]], self::schema($registry, 'Admin')->bases);
    }

    public function testAShapeMayBeBuiltFromASchemaAndASchemaFromAShape(): void
    {
        $registry = self::compiled(
            "%{Card: %shape includes User {- password}\n  Holder: %schema{card: Card}}",
            self::compiled(self::USERS),
        );
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%Holder{card: %{name: "a", email: "a@x"}}', $registry)));
        self::assertSame(
            'schema/type-mismatch',
            self::category(static fn () => Dextrin::decode('%Holder{card: %{name: "a"}}', $registry)),
        );
    }

    public function testAFieldReachedTwiceThroughACommonBaseIsOneField(): void
    {
        $registry = self::compiled(<<<'DXN'
            %{
              Person: %shape{name: :string}
              User: %schema extends Person {email: :string}
              Staff: %schema extends Person {desk: :integer}
              Both: %schema extends User Staff {}
            }
            DXN);
        self::assertSame([['name', 'string'], ['email', 'string'], ['desk', 'integer']], self::fields($registry, 'Both'));
    }

    public function testAFieldIsTheSameHoweverItsBaseWasFound(): void
    {
        $loaded = self::compiled(<<<'DXN'
            %{Id: {:refine :integer %{min: 1}}
              Person: %shape{id: Id}
              Staff: %schema extends Person {desk: :integer}}
            DXN);
        $registry = self::compiled(<<<'DXN'
            %{Id: {:refine :integer %{min: 1}}
              Person: %shape{id: Id}
              User: %schema extends Person {email: :string}
              Both: %schema extends User Staff {}}
            DXN, $loaded);
        self::assertSame(['id', 'email', 'desk'], array_column(self::fields($registry, 'Both'), 0));
    }

    public function testTwoDifferentFieldsOfOneNameAreRefusedUnlessTheBodySettlesIt(): void
    {
        $clash = "%{\n  A: %shape{x: :string}\n  B: %shape{x: :integer}\n  C: %schema includes A B {}\n}\n";
        self::assertSame('schema/compile', self::category(static fn () => self::compiled($clash)));
        self::assertSame('ok', self::category(static fn () => self::compiled(str_replace("{}\n", "{x: :boolean}\n", $clash))));
        self::assertSame('ok', self::category(static fn () => self::compiled(str_replace("{}\n", "{- x}\n", $clash))));
    }

    public function testEveryMistakeAgainstABaseIsSchemaCompile(): void
    {
        foreach ([
            '%{A: %schema extends Nobody {}}',
            '%{P: %shape{x: :string}, A: %schema extends P(2) {}}',
            '%{U: %schema(1){x: :string}, A: %schema extends U(2) {}}',
            '%{P: %shape{x: :string}, A: %schema includes P P {}}',
            '%{P: %shape{x: :string}, A: %schema extends P includes P {}}',
            '%{P: %shape{x: :string}, A: %schema includes P {- y}}',
            '%{P: %shape{x?: :string}, A: %schema includes P {- x, - x}}',
            '%{P: %shape{x?: :string}, A: %schema includes P {- x, x: :string}}',
            '%{P: %shape{x: :string}, A: %schema extends P {- x}}',
            '%{A: %schema extends B {}, B: %schema extends A {}}',
            '%{N: :integer, A: %schema extends N {}}',
            '%{G: {:generic [t] %shape{x: t}}, A: %schema extends G {}}',
            '%{A: %schema{- x}}',
            '%{P: %shape{x: :string}, A: %shape(2) extends P {}}',
            '%{P: %shape{x: :string}, A: %schema{y: %shape extends P {}}}',
            '%{P: %shape{k: :string, v?: :any}, A: %schema includes P {- k, w: {:case k %{"a" => :string}}}}',
        ] as $text) {
            self::assertSame('schema/compile', self::category(static fn () => self::compiled($text)), $text);
        }
    }

    public function testAnOptionalFieldOfAnExtendsBaseMayBeDroppedAndAnyFieldOfAnIncludesBase(): void
    {
        self::assertSame('ok', self::category(static fn () => self::compiled('%{P: %shape{x?: :string}, A: %schema extends P {- x}}')));
        self::assertSame('ok', self::category(static fn () => self::compiled('%{P: %shape{x: :string}, A: %schema includes P {- x}}')));
    }

    public function testACaseMayNameAFieldItsBaseGives(): void
    {
        $registry = self::compiled('%{P: %shape{k: :string}, A: %schema includes P {v: {:case k %{"a" => :string, :else => :integer}}}}');
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%A{k: "a", v: "x"}', $registry)));
        self::assertSame('schema/type-mismatch', self::category(static fn () => Dextrin::decode('%A{k: "a", v: 1}', $registry)));
    }

    // ---- a base in another document ------------------------------------------

    public function testABaseInAnotherDocumentIsItsLatestLoadedVersionUnlessOneIsNamed(): void
    {
        $base = self::compiled(self::V2, self::compiled(self::V1));
        $latest = self::compiled('%{A: %schema extends User {role: :string}}', $base);
        self::assertSame(['name', 'email', 'role'], array_column(self::fields($latest, 'A'), 0));
        self::assertSame([['extends', 'User', 2]], self::schema($latest, 'A')->bases);
        $named = self::compiled('%{A: %schema extends User(1) {role: :string}}', $base);
        self::assertSame(['name', 'role'], array_column(self::fields($named, 'A'), 0));
    }

    public function testTheLatestVersionMayBeOneOnlyTheResolverKnows(): void
    {
        $v2 = self::schema(self::compiled(self::V2), 'User');
        $base = (new Registry())->putResolver(static fn (string $name): array => $name === 'User' ? [$v2] : []);
        $registry = self::compiled('%{User: %schema(1){name: :string}, A: %schema extends User {role: :string}}', $base);
        self::assertSame(['name', 'email', 'role'], array_column(self::fields($registry, 'A'), 0));
    }

    public function testANewerVersionLoadedLaterChangesNoResultAlreadyLoaded(): void
    {
        $registry = self::compiled('%{A: %schema extends User {role: :string}}', self::compiled(self::V1));
        $registry = self::compiled(self::V2, $registry);
        self::assertSame(['name', 'role'], array_column(self::fields($registry, 'A'), 0));
    }

    // ---- where a base is expected --------------------------------------------

    private static function holder(Registry $registry): Registry
    {
        return self::compiled(<<<'DXN'
            %{
              UserBox: %schema{u: User}
              Wide: %schema extends User {email: :integer}
              Mixed: %schema includes User {}
            }
            DXN, $registry);
    }

    public function testAnExtendingStructIsAcceptedAndKeepsWhatItIs(): void
    {
        $r = self::holder(self::compiled(self::USERS));
        $box = Dextrin::decode(
            '%UserBox{u: %Admin{name: "a", email: "a@x", password: "p", changed_by: "b", role: "r"}}',
            $r,
        );
        self::assertInstanceOf(DXNStruct::class, $box);
        $u = $box->pairs()[0][1];
        self::assertInstanceOf(DXNStruct::class, $u);
        self::assertSame('Admin', $u->name);
    }

    public function testOnlyWhenItIsAValidBaseAsWell(): void
    {
        $r = self::holder(self::compiled(self::USERS));
        self::assertSame(
            'schema/type-mismatch',
            self::category(static fn () => Dextrin::decode('%UserBox{u: %Wide{name: "a", email: 1, password: "p"}}', $r)),
        );
    }

    public function testAPositionalStructOnItsWayOutAsWell(): void
    {
        $r = self::holder(self::compiled(self::USERS));
        $admin = DXNStruct::positional('Admin', ['a', new \JOetjen\Dextrin\Value\DXNDate(2000, 1, 1), 'a@x', 'p', 'b', 'r']);
        self::assertSame('ok', self::category(static fn () => Dextrin::encode(DXNStruct::keyed('UserBox', [['u', $admin]]), registry: $r)));
    }

    public function testThroughAChainOfExtendsAndNotPastAnIncludes(): void
    {
        $r = self::compiled(<<<'DXN'
            %{
              Root: %schema{id: :integer}
              Mid: %schema extends Root {}
              Leaf: %schema extends Mid {}
              Cut: %schema includes Mid {}
              RootBox: %schema{r: Root}
            }
            DXN);
        self::assertSame('ok', self::category(static fn () => Dextrin::decode('%RootBox{r: %Leaf{id: 1}}', $r)));
        self::assertSame('schema/type-mismatch', self::category(static fn () => Dextrin::decode('%RootBox{r: %Cut{id: 1}}', $r)));
    }

    public function testIncludingIsNotExtending(): void
    {
        $r = self::holder(self::compiled(self::USERS));
        self::assertSame(
            'schema/type-mismatch',
            self::category(static fn () => Dextrin::decode('%UserBox{u: %Mixed{name: "a", email: "a@x", password: "p"}}', $r)),
        );
    }

    public function testOnTheWayOutAnExtendingStructIsAcceptedWhereItsBaseIs(): void
    {
        $r = self::holder(self::compiled(self::USERS));
        $admin = DXNStruct::keyed('Admin', [['name', 'a'], ['email', 'a@x'], ['password', 'p'], ['changed_by', 'b'], ['role', 'r']]);
        $mixed = DXNStruct::keyed('Mixed', [['name', 'a'], ['email', 'a@x'], ['password', 'p']]);
        self::assertSame('ok', self::category(static fn () => Dextrin::encode(DXNStruct::keyed('UserBox', [['u', $admin]]), registry: $r)));
        self::assertSame(
            'schema/type-mismatch',
            self::category(static fn () => Dextrin::encode(DXNStruct::keyed('UserBox', [['u', $mixed]]), registry: $r)),
        );
    }
}
