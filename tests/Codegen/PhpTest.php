<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Codegen;

use JOetjen\Dextrin\Codegen\CodegenError;
use JOetjen\Dextrin\Codegen\InvalidData;
use JOetjen\Dextrin\Codegen\Php;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNUuid;
use PHPUnit\Framework\TestCase;

/**
 * PHP code from DXNS (T685, design/codegen-php.md), as the reference's
 * test/codegen/elixir_test.exs: generated classes read, check and write
 * data with code of their own -- no schema is loaded when they run -- for
 * every version a schema has; regenerating keeps what was written by
 * hand, in the user's file and in the generated file's regions, and the
 * class names as the user spells them.
 */
final class PhpTest extends TestCase
{
    /** @var array<string, string> */
    private const SCHEMAS = [
        'acme/shop.dxns' => <<<'DXN'
            %{
              Money: %shape{amount: {:refine :decimal %{min: 0M}} currency: %field{type: :string default: "EUR"}}
              Code: {:refine :string %{pattern: @regex "^[A-Z]{3}$"}}
              Status: {:enum :open :closed}
            }
            DXN,
        'acme/shop/Customer.dxns' => '%{Customer: %schema{name: :string, code?: acme.shop/Code}}',
        'acme/shop/Order.dxns' => <<<'DXN'
            %{Order: %schema{
              id: :uuid
              customer: acme.shop/Customer
              total: acme.shop/Money
              status: %field{type: acme.shop/Status default: :open}
              lines: {:list-of {:refine :string %{min-length: 1}}}
              note?: {:nilable :string}
            }}
            DXN,
        'acme/shop/Pet.dxns' => <<<'DXN'
            %{Pet: {:doc %schema(1){name: :string colour: :string born: :integer} %{deprecated: "use Pet(2)"}}}
            DXN,
        'acme/shop/Pet-v2.dxns' => <<<'DXN'
            %{Pet: %schema(2){
              name: :string
              color: {:doc :string %{meta: %{"typespec.renamed-from" => "colour"}}}
              born: :string
              tag?: :string
            }}
            DXN,
    ];

    /** @var array<string, string> */
    private const DEPRECATED_SCHEMAS = [
        'dep/Box.dxns' => '%{Box: {:doc %schema(1){a: :string} %{deprecated: "use Box(2)"}}}',
        'dep/Box-v2.dxns' => '%{Box: %schema(2){a: :string b?: :string}}',
        'dep/Old.dxns' => '%{Old: {:doc %schema{a: :string} %{deprecated: true}}}',
        'dep.dxns' => <<<'DXN'
            %{
              Color: {:doc {:enum :red :old} %{values: %{:old => %{deprecated: "use red"}}}}
              Legacy: {:doc :string %{deprecated: "use text"}}
              Cat: %shape{meows: :boolean}
              Dog: {:doc %shape{barks: :boolean} %{deprecated: "no dogs"}}
            }
            DXN,
        'dep/Item.dxns' => <<<'DXN'
            %{Item: %schema{
              color?: dep/Color
              note?: dep/Legacy
              pet?: {:any-of dep/Dog dep/Cat}
            }}
            DXN,
    ];

    /** The hand-written upgrade Pet(1) needs: `born` changed type. */
    private const PET_UPGRADE = <<<'PHP'

            protected static function upgradeV1(array $fields): array
            {
                $fields['born'] = (string) $fields['born'];
                return $fields;
            }
        }
        PHP;

    /** @var list<string> */
    private array $roots = [];

    /** @var list<callable> */
    private array $autoloaders = [];

    /** @var list<array{name: ?string, version: ?int, message: ?string, category: string, at: ?string}> */
    private array $heard = [];

    protected function tearDown(): void
    {
        foreach ($this->autoloaders as $loader) {
            spl_autoload_unregister($loader);
        }
        foreach ($this->roots as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    // ---- helpers -------------------------------------------------------------------

    /**
     * A fresh directory holding `$files` under `schemas/`.
     *
     * @param array<string, string> $files
     */
    private function schemasDir(array $files = self::SCHEMAS): string
    {
        $root = sys_get_temp_dir() . '/dextrin-codegen-' . bin2hex(random_bytes(6));
        $this->roots[] = $root;
        foreach ($files as $path => $text) {
            $full = "{$root}/schemas/{$path}";
            @mkdir(dirname($full), 0777, true);
            file_put_contents($full, $text);
        }
        return $root;
    }

    /** A namespace no other test uses: the classes it names are loaded once per process. */
    private static function ns(): string
    {
        return 'Gen' . bin2hex(random_bytes(5));
    }

    /** @return list<string> */
    private static function generate(string $root, string $ns): array
    {
        return Php::write(["{$root}/schemas"], $ns, "{$root}/src");
    }

    /** The classes of `$ns` loaded from `$root/src`, PSR-4, when first used. */
    private function autoload(string $root, string $ns): void
    {
        $loader = static function (string $class) use ($root, $ns): void {
            if (str_starts_with($class, $ns . '\\')) {
                $file = "{$root}/src/" . str_replace('\\', '/', substr($class, strlen($ns) + 1)) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($loader);
        $this->autoloaders[] = $loader;
    }

    /** @return class-string */
    private static function cls(string $ns, string $name): string
    {
        /** @var class-string */
        return $ns . '\\' . $name;
    }

    private static function dxn(string $text): mixed
    {
        return Dextrin::decode($text);
    }

    private static function read(string $file): string
    {
        return (string) file_get_contents($file);
    }

    private function hear(): \Closure
    {
        return function (array $warning): void {
            /** @var array{name: ?string, version: ?int, message: ?string, category: string, at: ?string} $warning */
            $this->heard[] = $warning;
        };
    }

    // ---- the files ------------------------------------------------------------------

    public function testAUsersFileAndAGeneratedOnePerSchemaEachNamingTheSchema(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        $written = self::generate($root, $ns);

        self::assertContains("{$root}/src/Acme/Shop/Order.php", $written);
        self::assertContains("{$root}/src/Acme/Shop/AbstractOrder.php", $written);
        self::assertContains("{$root}/src/Decoder.php", $written);

        $gen = self::read("{$root}/src/Acme/Shop/AbstractOrder.php");
        $own = self::read("{$root}/src/Acme/Shop/Order.php");

        self::assertStringStartsWith("<?php\n\n// Generated by", $gen);
        self::assertStringContainsString('// dextrin:schema acme.shop/Order', $gen);
        self::assertStringContainsString('do not edit', $gen);
        self::assertStringContainsString("abstract class AbstractOrder\n", $gen);
        self::assertStringStartsWith("<?php\n\n// dextrin:schema acme.shop/Order\n", $own);
        self::assertStringContainsString("namespace {$ns}\\Acme\\Shop;", $own);
        self::assertStringContainsString('class Order extends AbstractOrder', $own);
    }

    public function testTheSameSchemasGiveTheSameBytes(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $first = self::read("{$root}/src/Acme/Shop/AbstractOrder.php");
        self::generate($root, $ns);
        self::assertSame($first, self::read("{$root}/src/Acme/Shop/AbstractOrder.php"));
    }

    public function testTheLatestVersionsFieldsArePropertiesOfNativeTypesWhereExact(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        $order = new \ReflectionClass(self::cls($ns, 'Acme\Shop\Order'));

        $type = static fn (string $name): string => (string) $order->getProperty($name)->getType();
        self::assertSame(DXNUuid::class, $type('id'));
        self::assertSame($ns . '\Acme\Shop\Customer', $type('customer'));
        self::assertSame(DXNKeyword::class, $type('status'));
        self::assertSame('?string', $type('note'));
        self::assertTrue($order->getProperty('id')->isReadOnly());
    }

    // ---- reading and checking --------------------------------------------------------

    public function testAStructIsReadIntoTheClassItsDefaultsFilled(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        $order = self::cls($ns, 'Acme\Shop\Order');
        $customer = self::cls($ns, 'Acme\Shop\Customer');

        $read = $order::read(self::dxn(<<<'DXN'
            %acme.shop/Order{
              id: @uuid "550e8400-e29b-41d4-a716-446655440000"
              customer: %acme.shop/Customer{name: "Ada"}
              total: %{amount: 9.5M}
              lines: ["a" "b"]
            }
            DXN));

        self::assertInstanceOf($order, $read);
        self::assertInstanceOf($customer, $read->customer);
        self::assertSame('Ada', $read->customer->name);
        self::assertEquals(new DXNKeyword('open'), $read->status);
        self::assertSame('EUR', $read->total['currency']);
        self::assertNull($read->note);
        $read->validate();
    }

    public function testAValueThatDoesNotMatchSaysWhichField(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        $order = self::cls($ns, 'Acme\Shop\Order');

        try {
            $order::read(self::dxn(<<<'DXN'
                %acme.shop/Order{
                  id: @uuid "550e8400-e29b-41d4-a716-446655440000"
                  customer: %acme.shop/Customer{name: "Ada" code: "nope"}
                  total: %{amount: -1M}
                  lines: [""]
                }
                DXN));
            self::fail('refused');
        } catch (InvalidData $e) {
            self::assertInstanceOf(DXNError::class, $e);
            self::assertSame(['customer', 'code'], $e->path);
            self::assertStringStartsWith('customer.code: ', $e->getMessage());
        }
    }

    public function testAMissingRequiredFieldIsRefused(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        $customer = self::cls($ns, 'Acme\Shop\Customer');

        $this->expectException(InvalidData::class);
        $this->expectExceptionMessage('missing required field name');
        $customer::read(self::dxn('%acme.shop/Customer{}'));
    }

    public function testValidateChecksAValueBuiltByHand(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        $customer = self::cls($ns, 'Acme\Shop\Customer');

        (new $customer(name: 'Ada', code: 'ABC'))->validate();
        $this->expectException(InvalidData::class);
        $this->expectExceptionMessage('code: ');
        (new $customer(name: 'Ada', code: 'abc'))->validate();
    }

    public function testWithCopiesChangeOneFieldAndLeaveTheOriginal(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        $customer = self::cls($ns, 'Acme\Shop\Customer');

        $ada = new $customer(name: 'Ada');
        $bo = $ada->withName('Bo');
        self::assertInstanceOf($customer, $bo);
        self::assertSame('Bo', $bo->name);
        self::assertSame('Ada', $ada->name);
    }

    public function testARequiredFieldThatMayBeNullKeepsItsNullThroughValidateAndToDxn(): void
    {
        $root = $this->schemasDir(['a/Memo.dxns' => '%{Memo: %schema{note: {:nilable :string} done: :boolean tag?: :string}}']);
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        $memo = self::cls($ns, 'A\\Memo');

        $read = $memo::read(self::dxn('%a/Memo{note: nil done: false}'));
        self::assertNull($read->note);
        $read->validate();
        $struct = $read->toDxn();
        self::assertSame([['note', null], ['done', false]], $struct->pairs());
    }

    public function testAWithCopyOfASnakeCaseFieldIsNamedInCamelCase(): void
    {
        $root = $this->schemasDir(['a/Stamp.dxns' => '%{Stamp: %schema{signed_up_at: :string}}']);
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        $stamp = self::cls($ns, 'A\Stamp');

        $copy = (new $stamp(signed_up_at: 'x'))->withSignedUpAt('y');
        self::assertSame('y', $copy->signed_up_at);
    }

    public function testToDxnWritesTheStructBackAtTheLatestVersion(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        $customer = self::cls($ns, 'Acme\Shop\Customer');

        $read = $customer::read(self::dxn('%acme.shop/Customer{name: "Ada"}'));
        $struct = $read->toDxn();
        self::assertInstanceOf(DXNStruct::class, $struct);
        self::assertStringContainsString('%acme.shop/Customer(1){name:"Ada"}', Dextrin::encode($struct));
    }

    // ---- versions ------------------------------------------------------------------

    public function testAnOlderVersionIsReadAndUpgradedRenamedDroppedAndAddedFieldsByThemselvesAChangedTypeByHand(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $own = "{$root}/src/Acme/Shop/Pet.php";
        file_put_contents($own, (string) preg_replace('/\n}\n$/', self::PET_UPGRADE . "\n", self::read($own)));
        $this->autoload($root, $ns);
        $pet = self::cls($ns, 'Acme\Shop\Pet');

        $read = $pet::read(self::dxn('%acme.shop/Pet(1){name: "Rex" colour: "red" born: 2019}'), deprecations: 'off');
        self::assertSame(['Rex', 'red', '2019', null], [$read->name, $read->color, $read->born, $read->tag]);

        $read = $pet::read(self::dxn('%acme.shop/Pet(2){name: "Rex" color: "blue" born: "2019"}'));
        self::assertSame('blue', $read->color);
    }

    public function testAHandWrittenUpgradeIsAnAbstractMethodOfTheGeneratedClass(): void
    {
        $root = $this->schemasDir();
        self::generate($root, self::ns());
        $gen = self::read("{$root}/src/Acme/Shop/AbstractPet.php");
        self::assertStringContainsString('abstract protected static function upgradeV1(array $fields): array;', $gen);
        self::assertStringNotContainsString('function upgradeV1(', self::read("{$root}/src/Acme/Shop/Pet.php"));
    }

    public function testADeprecatedVersionsMethodsAreMarked(): void
    {
        $root = $this->schemasDir();
        self::generate($root, self::ns());
        $gen = self::read("{$root}/src/Acme/Shop/AbstractPet.php");
        self::assertMatchesRegularExpression('/@deprecated use Pet\(2\)\n(?:\s+\*[^\n]*\n)*\s+\*\/\n\s+#\[\\\\Deprecated\(message: \'use Pet\(2\)\'\)\]\n\s+public static function readV1\(/', $gen);
        self::assertMatchesRegularExpression('/#\[\\\\Deprecated\(message: \'use Pet\(2\)\'\)\]\n\s+public static function conformV1\(/', $gen);
        self::assertDoesNotMatchRegularExpression('/#\[\\\\Deprecated[^\n]*\n\s+public static function (read|conform)V2\(/', $gen);
    }

    // ---- what is written by hand is kept ----------------------------------------------

    public function testARegionOfTheGeneratedFileSurvivesRegenerating(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $gen = "{$root}/src/Acme/Shop/AbstractOrder.php";
        file_put_contents($gen, str_replace(
            "    // dextrin:keep bottom\n",
            "    // dextrin:keep bottom\n    public function hello(): string\n    {\n        return 'world';\n    }\n",
            self::read($gen),
        ));

        self::generate($root, $ns);
        self::assertStringContainsString(
            "    // dextrin:keep bottom\n    public function hello(): string\n    {\n        return 'world';\n    }\n    // dextrin:end bottom",
            self::read($gen),
        );
    }

    public function testARegionWhoseSlotIsGoneIsKeptAsAnOrphanAndCheckFails(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $gen = "{$root}/src/Acme/Shop/AbstractPet.php";
        file_put_contents($gen, str_replace(
            "        // dextrin:keep upgrade_v1\n",
            "        // dextrin:keep upgrade_v1\n        \$fields['x'] = 1;\n",
            self::read($gen),
        ));

        unlink("{$root}/schemas/acme/shop/Pet.dxns");
        self::generate($root, $ns);
        $text = self::read($gen);
        self::assertStringContainsString('// dextrin:keep orphaned_upgrade_v1', $text);
        self::assertStringContainsString("\$fields['x'] = 1;", $text);
        // Kept past the end of the code, so the file still loads.
        self::assertStringContainsString("__halt_compiler();\n", $text);
        self::assertSame(0, self::lint($gen));

        $problems = Php::check(["{$root}/schemas"], $ns, "{$root}/src");
        self::assertNotEmpty(array_filter($problems, static fn (string $p): bool => str_contains($p, 'orphaned')));
    }

    public function testTheUsersFileIsNeverRewrittenAndItsClassNameIsKeptAsSpelled(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $own = "{$root}/src/Acme/Shop/Customer.php";
        file_put_contents($own, str_replace(
            ['class Customer extends AbstractCustomer', "\n}\n"],
            ['class Client extends AbstractClient', "\n    public function mine(): string\n    {\n        return 'kept';\n    }\n}\n"],
            self::read($own),
        ));

        self::generate($root, $ns);
        self::assertStringContainsString("return 'kept';", self::read($own));
        self::assertFileExists("{$root}/src/Acme/Shop/AbstractClient.php");
        self::assertFileDoesNotExist("{$root}/src/Acme/Shop/AbstractCustomer.php");
        self::assertStringContainsString('abstract class AbstractClient', self::read("{$root}/src/Acme/Shop/AbstractClient.php"));
        self::assertStringContainsString("\\{$ns}\\Acme\\Shop\\Client::read(", self::read("{$root}/src/Acme/Shop/AbstractOrder.php"));
        self::assertSame([], Php::check(["{$root}/schemas"], $ns, "{$root}/src"));
    }

    public function testASchemaNamedLikeAnothersGeneratedParentIsRefusedNamingBoth(): void
    {
        $root = $this->schemasDir([
            'acme/Order.dxns' => '%{Order: %schema{a: :string}}',
            'acme/AbstractOrder.dxns' => '%{AbstractOrder: %schema{a: :string}}',
        ]);
        try {
            self::generate($root, self::ns());
            self::fail('refused');
        } catch (CodegenError $e) {
            self::assertStringContainsString('acme/AbstractOrder', $e->getMessage());
            self::assertStringContainsString('acme/Order', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist("{$root}/src");
    }

    public function testASchemaNamedLikeTheDecoderIsRefused(): void
    {
        $root = $this->schemasDir(['Decoder.dxns' => '%{Decoder: %schema{a: :string}}']);
        $this->expectException(CodegenError::class);
        $this->expectExceptionMessage('the generated decoder and the schema Decoder');
        self::generate($root, self::ns());
    }

    // ---- --check ---------------------------------------------------------------------

    public function testCheckPassesWhenTheFilesAreUpToDateAndNamesTheStaleOnesOtherwise(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        self::assertSame([], Php::check(["{$root}/schemas"], $ns, "{$root}/src"));

        file_put_contents(
            "{$root}/schemas/acme/shop/Customer.dxns",
            '%{Customer: %schema{name: :string, code?: acme.shop/Code, vip?: :boolean}}',
        );
        $problems = Php::check(["{$root}/schemas"], $ns, "{$root}/src");
        self::assertNotEmpty(array_filter($problems, static fn (string $p): bool => str_contains($p, 'AbstractCustomer.php')));
    }

    // ---- decoding into the generated classes ---------------------------------------------

    public function testTheDecoderReadsEveryKnownStructWithTheGeneratedMethods(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        $decoder = self::cls($ns, 'Decoder');
        $customer = self::cls($ns, 'Acme\Shop\Customer');

        $value = $decoder::decode('[%acme.shop/Customer{name: "Ada"} %acme.shop/Customer{name: "Bo"} 3]');
        self::assertInstanceOf(\JOetjen\Dextrin\Value\DXNList::class, $value);
        [$ada, $bo, $three] = $value->items;
        self::assertInstanceOf($customer, $ada);
        self::assertSame('Ada', $ada->name);
        self::assertInstanceOf($customer, $bo);
        self::assertSame(3, $three);
        self::assertSame($customer, $decoder::classes()['acme.shop/Customer']);

        $this->expectException(InvalidData::class);
        $this->expectExceptionMessage('name');
        $decoder::decode('[%acme.shop/Customer{}]');
    }

    // ---- deprecation warnings (T698) ------------------------------------------------------

    /** @return array{0: string, 1: string} the root and the namespace, generated and loadable */
    private function deprecated(): array
    {
        $root = $this->schemasDir(self::DEPRECATED_SCHEMAS);
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        return [$root, $ns];
    }

    public function testAStructReadAtADeprecatedVersionWarnsOnceByDefault(): void
    {
        [, $ns] = $this->deprecated();
        $box = self::cls($ns, 'Dep\Box');
        $v1 = self::dxn('%dep/Box(1){a: "x"}');

        self::assertSame('x', $box::read($v1, onDeprecated: $this->hear())->a);
        $box::read($v1, onDeprecated: $this->hear());
        self::assertSame(
            [['name' => 'dep/Box', 'version' => 1, 'message' => 'use Box(2)', 'category' => 'schema/deprecated', 'at' => null]],
            $this->heard,
        );

        $box::read(self::dxn('%dep/Box(2){a: "x"}'), onDeprecated: $this->hear());
        self::assertCount(1, $this->heard);
    }

    public function testASchemaWhoseLatestVersionIsDeprecatedWarnsWhateverVersionIsRead(): void
    {
        [, $ns] = $this->deprecated();
        self::cls($ns, 'Dep\Old')::read(self::dxn('%dep/Old{a: "x"}'), onDeprecated: $this->hear());
        self::assertCount(1, $this->heard);
        self::assertSame(['dep/Old', 1, null], [$this->heard[0]['name'], $this->heard[0]['version'], $this->heard[0]['message']]);
    }

    public function testEveryWarnsAtEachUseOffNeverErrorRefuses(): void
    {
        [, $ns] = $this->deprecated();
        $box = self::cls($ns, 'Dep\Box');
        $v1 = self::dxn('%dep/Box(1){a: "x"}');

        $box::read($v1, deprecations: 'every', onDeprecated: $this->hear());
        $box::read($v1, deprecations: 'every', onDeprecated: $this->hear());
        self::assertCount(2, $this->heard);

        $box::read($v1, deprecations: 'off', onDeprecated: $this->hear());
        self::assertCount(2, $this->heard);

        try {
            $box::read($v1, deprecations: 'error', onDeprecated: $this->hear());
            self::fail('refused');
        } catch (InvalidData $e) {
            self::assertSame('dep/Box(1) is deprecated: use Box(2)', $e->getMessage());
            self::assertSame('schema/deprecated', $e->category);
        }
        self::assertCount(2, $this->heard);
    }

    public function testAnUnknownModeIsRefused(): void
    {
        [, $ns] = $this->deprecated();
        $this->expectException(\InvalidArgumentException::class);
        self::cls($ns, 'Dep\Box')::read(self::dxn('%dep/Box(2){a: "x"}'), deprecations: 'sometimes');
    }

    public function testADeprecatedTypeEnumValueOrVariantWarnsWhenAValueGoesThroughIt(): void
    {
        [, $ns] = $this->deprecated();
        $item = self::cls($ns, 'Dep\Item');

        $item::read(self::dxn('%dep/Item{color: :old}'), deprecations: 'every', onDeprecated: $this->hear());
        self::assertSame(['use red'], array_column($this->heard, 'message'));

        $item::read(self::dxn('%dep/Item{color: :red note: "n"}'), deprecations: 'every', onDeprecated: $this->hear());
        self::assertSame(['use red', 'use text'], array_column($this->heard, 'message'));

        $item::read(self::dxn('%dep/Item{pet: %{barks: true}}'), deprecations: 'every', onDeprecated: $this->hear());
        self::assertSame(['use red', 'use text', 'no dogs'], array_column($this->heard, 'message'));
    }

    public function testADeprecatedVariantTriedAndRefusedStaysSilent(): void
    {
        [, $ns] = $this->deprecated();
        self::cls($ns, 'Dep\Item')::read(self::dxn('%dep/Item{pet: %{meows: true}}'), deprecations: 'every', onDeprecated: $this->hear());
        self::assertSame([], $this->heard);
    }

    public function testWithErrorADeprecatedValueRefusesTheStructHoldingIt(): void
    {
        [, $ns] = $this->deprecated();
        $this->expectException(InvalidData::class);
        $this->expectExceptionMessage('deprecated: use red');
        self::cls($ns, 'Dep\Item')::read(self::dxn('%dep/Item{color: :old}'), deprecations: 'error');
    }

    public function testWithoutOnDeprecatedTheWarningIsAUserDeprecation(): void
    {
        [, $ns] = $this->deprecated();
        $triggered = [];
        set_error_handler(static function (int $level, string $message) use (&$triggered): bool {
            $triggered[] = [$level, $message];
            return true;
        });
        try {
            self::cls($ns, 'Dep\Box')::read(self::dxn('%dep/Box(1){a: "x"}'));
        } finally {
            restore_error_handler();
        }
        self::assertSame([[E_USER_DEPRECATED, 'dep/Box(1) is deprecated: use Box(2)']], $triggered);
    }

    public function testTheDecoderTakesTheSameOptions(): void
    {
        [, $ns] = $this->deprecated();
        $decoder = self::cls($ns, 'Decoder');

        $decoder::decode('[%dep/Box(1){a: "x"} %dep/Box(1){a: "y"}]', deprecations: 'every', onDeprecated: $this->hear());
        self::assertSame(['dep/Box', 'dep/Box'], array_column($this->heard, 'name'));

        $this->expectException(InvalidData::class);
        $decoder::decode('[%dep/Box(1){a: "x"}]', deprecations: 'error');
    }

    // ---- what the generator does not write yet --------------------------------------------

    public function testWhatIsNotGeneratedYetIsRefusedNamingEverySchemaVersionAndFieldAndNothingIsWritten(): void
    {
        $root = $this->schemasDir([
            'a/Pair.dxns' => '%{Pair: %schema{pair: {:tuple-of :string :integer} name: :string}}',
            'a/Tags.dxns' => '%{Tags: %schema{tags: {:set-of :string}}}',
            'a/Fine.dxns' => '%{Fine: %schema{name: :string}}',
        ]);
        try {
            self::generate($root, self::ns());
            self::fail('refused');
        } catch (CodegenError $e) {
            self::assertCount(2, $e->problems);
            self::assertSame('a/Pair(1): field pair: {:tuple-of ...} is not generated yet', $e->problems[0]);
            self::assertStringContainsString('a/Tags(1): field tags:', $e->problems[1]);
        }
        self::assertDirectoryDoesNotExist("{$root}/src");
    }

    public function testTypedExtrasAreRefusedNamedAsDxnsWritesThem(): void
    {
        $root = $this->schemasDir(['a/Bag.dxns' => '%{Bag: %schema{inner: %shape{a: :string * :string => :integer}}}']);
        try {
            self::generate($root, self::ns());
            self::fail('refused');
        } catch (CodegenError $e) {
            self::assertSame(['a/Bag(1): field inner: typed extras (`* K => V`) are not generated yet'], $e->problems);
        }
    }

    public function testAClosedSchemaRefusesAFieldItsVersionDoesNotDeclareBeforeAnyIsChecked(): void
    {
        $root = $this->schemasDir([
            'a/Shut.dxns' => '%{Shut: %schema{a: :string - *}}',
            'a/Open.dxns' => '%{Open: %schema{a: :string}}',
        ]);
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);

        self::assertSame('x', self::cls($ns, 'A\\Open')::read(self::dxn('%a/Open{a: "x" b: 1}'))->a);
        self::assertSame('x', self::cls($ns, 'A\\Shut')::read(self::dxn('%a/Shut{a: "x"}'))->a);
        try {
            self::cls($ns, 'A\\Shut')::read(self::dxn('%a/Shut{a: 1 b: 1}'));
            self::fail('refused');
        } catch (InvalidData $e) {
            self::assertSame('field "b" is not declared, and the schema takes no other', $e->getMessage());
            self::assertSame('schema/undeclared-field', $e->category);
        }
    }

    // ---- names, defaults and absent fields ------------------------------------------------

    public function testANamespaceWrittenWithCapitalsOrNotIsOnePsr4DirectoryPerPart(): void
    {
        $root = $this->schemasDir([
            'acme/shop/Order/v1.dxns' => '%{Order: %schema{line: OrderLine} OrderLine: %schema{sku: :string}}',
        ]);
        $ns = self::ns();
        $written = self::generate($root, $ns);
        self::assertContains("{$root}/src/Acme/Shop/Order/OrderLine.php", $written);
        self::assertContains("{$root}/src/Acme/Shop/Order.php", $written);
        self::assertStringContainsString(
            "namespace {$ns}\\Acme\\Shop\\Order;",
            self::read("{$root}/src/Acme/Shop/Order/OrderLine.php"),
        );
    }

    public function testAComputedDefaultIsComputedWhenAValueIsReadNotWhenTheCodeIsWritten(): void
    {
        $root = $this->schemasDir(['a/Stamp.dxns' => '%{Stamp: %schema{at: %field{type: :timestamp computed: :now}}}']);
        $ns = self::ns();
        self::generate($root, $ns);
        $first = self::read("{$root}/src/A/AbstractStamp.php");
        sleep(1);
        self::generate($root, $ns);
        self::assertSame($first, self::read("{$root}/src/A/AbstractStamp.php"));

        $this->autoload($root, $ns);
        $before = time();
        $at = self::cls($ns, 'A\Stamp')::read(self::dxn('%a/Stamp{}'))->at;
        self::assertInstanceOf(\JOetjen\Dextrin\Value\DXNDateTime::class, $at);
        self::assertGreaterThanOrEqual($before, intdiv($at->epochNanoseconds, 1_000_000_000));
    }

    public function testAFieldThatMustBeAbsentHasNoCheckAndRefusesAValue(): void
    {
        $root = $this->schemasDir(['a/Gone.dxns' => '%{Gone: %schema{old?: :never inner: %shape{x?: :never y: :string}}}']);
        $ns = self::ns();
        self::generate($root, $ns);
        $this->autoload($root, $ns);
        $gone = self::cls($ns, 'A\Gone');

        self::assertNull($gone::read(self::dxn('%a/Gone{inner: %{y: "a"}}'))->old);
        foreach (['%a/Gone{old: 1 inner: %{y: "a"}}' => 'old: no value is allowed here', '%a/Gone{inner: %{x: 1 y: "a"}}' => 'inner.x: no value is allowed here'] as $text => $why) {
            try {
                $gone::read(self::dxn($text));
                self::fail('refused');
            } catch (InvalidData $e) {
                self::assertSame($why, $e->getMessage());
            }
        }
    }

    // ---- bin/dextrin gen-php ----------------------------------------------------------------

    public function testTheCommandWritesTheFilesAndCheckReportsWhatIsOutOfDate(): void
    {
        $root = $this->schemasDir();
        $ns = self::ns();
        $args = ["{$root}/schemas", '--namespace', $ns, '--out', "{$root}/src"];

        [$status, $out] = self::command($args);
        self::assertSame(0, $status, $out);
        self::assertFileExists("{$root}/src/Acme/Shop/AbstractOrder.php");

        [$status, $out] = self::command([...$args, '--check']);
        self::assertSame(0, $status, $out);
        self::assertStringContainsString('up to date', $out);

        unlink("{$root}/src/Acme/Shop/AbstractOrder.php");
        [$status, $out] = self::command([...$args, '--check']);
        self::assertSame(1, $status, $out);
        self::assertStringContainsString('AbstractOrder.php is missing', $out);
    }

    // ---- the generated fixture ------------------------------------------------------------

    public function testTheGeneratedFixtureIsUpToDate(): void
    {
        $fixture = self::FIXTURE;
        self::assertSame([], Php::check(["{$fixture}/schemas"], self::FIXTURE_NS, "{$fixture}/Generated"));
    }

    public function testTheGeneratedFixturePassesPhpstanAtLevel8(): void
    {
        $log = sys_get_temp_dir() . '/dextrin-codegen-phpstan-' . bin2hex(random_bytes(6)) . '.log';
        $this->roots[] = $log;
        exec(
            escapeshellarg(PHP_BINARY) . ' vendor/bin/phpstan analyse --level=8 --no-progress --memory-limit=512M '
                . escapeshellarg(self::FIXTURE . '/Generated') . ' > ' . escapeshellarg($log) . ' 2>&1',
            $ignored,
            $status,
        );
        self::assertSame(0, $status, self::read($log));
    }

    public function testTheGeneratedFixtureReadsAnUpgradedPet(): void
    {
        $pet = self::FIXTURE_NS . '\Acme\Shop\Pet';
        $read = $pet::read(self::dxn('%acme.shop/Pet(1){name: "Rex" colour: "red" born: 2019}'), deprecations: 'off');
        self::assertSame(['Rex', 'red', '2019'], [$read->name, $read->color, $read->born]);
    }

    public function testTheGeneratedFixtureReadsEveryFormItGenerates(): void
    {
        $sink = self::FIXTURE_NS . '\Acme\Shop\Sink';
        $read = $sink::read(self::dxn(<<<'DXN'
            %acme.shop/Sink{
              first-name: "Ada" count: "3" scores: %{"a" => 1.5} both: 2 odd: 1 pick: 7
              tree: %{label: "root" children: [%{label: "leaf"}]}
              closed: %{a: "x"}
            }
            DXN));
        self::assertSame(['Ada', '3', 2, 1, 7, false], [$read->firstName, $read->count, $read->both, $read->odd, $read->pick, $read->seen]);
        self::assertInstanceOf(\JOetjen\Dextrin\Value\DXNDateTime::class, $read->at);
        self::assertNull($read->gone);
        $read->validate();

        foreach ([
            'both: 0' => 'both: ',
            'odd: "s"' => 'odd: "s" is what is excluded',
            'pick: 10' => 'pick: 10 does not meet',
            'scores: %{"a" => 1}' => 'scores.',
            'closed: %{a: "x" b: 1}' => 'closed: :b is no field, and the shape is closed',
            'tree: %{label: "r" children: [%{label: 1}]}' => 'tree.children[0].label: expected string',
        ] as $change => $why) {
            $field = explode(':', $change)[0];
            $text = (string) preg_replace('/\b' . $field . ': (%\{[^}]*\}\]?\}?|\S+)/', $change, 'first-name: "Ada" count: 3 scores: %{"a" => 1.5} both: 2 odd: 1 pick: 7 tree: %{label: "root"} closed: %{a: "x"}');
            try {
                $sink::read(self::dxn("%acme.shop/Sink{{$text}}"));
                self::fail("refused: {$change}");
            } catch (InvalidData $e) {
                self::assertStringStartsWith($why, $e->getMessage(), $change);
            }
        }
    }

    private const FIXTURE = __DIR__ . '/../Fixtures/Codegen';
    private const FIXTURE_NS = 'JOetjen\Dextrin\Tests\Fixtures\Codegen\Generated';

    /**
     * @param list<string> $args
     * @return array{int, string} exit status and everything printed
     */
    private static function command(array $args): array
    {
        exec(
            escapeshellarg(PHP_BINARY) . ' bin/dextrin gen-php ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1',
            $out,
            $status,
        );
        return [$status, implode("\n", $out)];
    }

    private static function lint(string $file): int
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $ignored, $status);
        return $status;
    }
}
