<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Codegen;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema\Compiled;
use JOetjen\Dextrin\Schema\Computed;
use JOetjen\Dextrin\Schema\Deprecations;
use JOetjen\Dextrin\Schema\Field;
use JOetjen\Dextrin\Schema\TypeExpr;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNMap;

/**
 * One schema's generated class, `Abstract<Class>` ({@see Php}): the
 * source of the file the generator rewrites, its regions empty.
 *
 * Each distinct type a field uses becomes one private check method
 * (`t1()`, `t2()`, ...) answering the value checked -- a shape with its
 * defaults filled, a reference read into its class -- or throwing
 * {@see InvalidData}; a recursive named type's is named after it. Every
 * constant a check needs (a default, an enum's literals, a refine's
 * constraints) is written as DXN text and decoded the first time it is
 * needed.
 *
 * @internal
 * @phpstan-type ClassInfo array{namespace: string, class: string, fqcn: string, user: string, gen: string}
 */
final class PhpClass
{
    /** The classes a generated file may name without their namespace. */
    private const IMPORTS = [
        'InvalidData' => InvalidData::class,
        'Runtime' => Runtime::class,
        'Field' => Field::class,
        'Computed' => Computed::class,
        'DXNBits' => \JOetjen\Dextrin\Value\DXNBits::class,
        'DXNBytes' => \JOetjen\Dextrin\Value\DXNBytes::class,
        'DXNChar' => \JOetjen\Dextrin\Value\DXNChar::class,
        'DXNDate' => \JOetjen\Dextrin\Value\DXNDate::class,
        'DXNDateTime' => \JOetjen\Dextrin\Value\DXNDateTime::class,
        'DXNDecimal' => \JOetjen\Dextrin\Value\DXNDecimal::class,
        'DXNDuration' => \JOetjen\Dextrin\Value\DXNDuration::class,
        'DXNInteger' => \JOetjen\Dextrin\Value\DXNInteger::class,
        'DXNIp' => \JOetjen\Dextrin\Value\DXNIp::class,
        'DXNKeyword' => DXNKeyword::class,
        'DXNList' => \JOetjen\Dextrin\Value\DXNList::class,
        'DXNLocalDateTime' => \JOetjen\Dextrin\Value\DXNLocalDateTime::class,
        'DXNMap' => DXNMap::class,
        'DXNObjectId' => \JOetjen\Dextrin\Value\DXNObjectId::class,
        'DXNRational' => \JOetjen\Dextrin\Value\DXNRational::class,
        'DXNRegex' => \JOetjen\Dextrin\Value\DXNRegex::class,
        'DXNStruct' => \JOetjen\Dextrin\Value\DXNStruct::class,
        'DXNSymbol' => \JOetjen\Dextrin\Value\DXNSymbol::class,
        'DXNTime' => \JOetjen\Dextrin\Value\DXNTime::class,
        'DXNTuple' => \JOetjen\Dextrin\Value\DXNTuple::class,
        'DXNUri' => \JOetjen\Dextrin\Value\DXNUri::class,
        'DXNUuid' => \JOetjen\Dextrin\Value\DXNUuid::class,
    ];

    /** A primitive's PHP type, native and as PHPDoc says it more exactly. */
    private const PRIMITIVE_TYPES = [
        'nil' => [['null', 'null']],
        'boolean' => [['bool', 'bool']],
        'integer' => [['int', 'int'], ['DXNInteger', 'DXNInteger']],
        'float' => [['float', 'float']],
        'decimal' => [['DXNDecimal', 'DXNDecimal']],
        'rational' => [['DXNRational', 'DXNRational']],
        'string' => [['string', 'string']],
        'char' => [['DXNChar', 'DXNChar']],
        'symbol' => [['DXNSymbol', 'DXNSymbol']],
        'keyword' => [['DXNKeyword', 'DXNKeyword']],
        'list' => [['DXNList', 'DXNList']],
        'tuple' => [['DXNTuple', 'DXNTuple']],
        'map' => [['array', 'array<int|string, mixed>'], ['DXNMap', 'DXNMap']],
        'array' => [['array', 'list<mixed>']],
        'date' => [['DXNDate', 'DXNDate']],
        'time' => [['DXNTime', 'DXNTime']],
        'datetime' => [['DXNDateTime', 'DXNDateTime'], ['\DateTimeInterface', '\DateTimeInterface']],
        'local-datetime' => [['DXNLocalDateTime', 'DXNLocalDateTime']],
        'duration' => [['DXNDuration', 'DXNDuration']],
        'uuid' => [['DXNUuid', 'DXNUuid']],
        'objectid' => [['DXNObjectId', 'DXNObjectId']],
        'ip' => [['DXNIp', 'DXNIp']],
        'uri' => [['DXNUri', 'DXNUri']],
        'bytes' => [['DXNBytes', 'DXNBytes']],
        'bits' => [['DXNBits', 'DXNBits'], ['DXNBytes', 'DXNBytes']],
        'regex' => [['DXNRegex', 'DXNRegex']],
    ];

    /** Primitives checked inline, without {@see Runtime::primitive()}. */
    private const INLINE = [
        'string' => 'is_string($v)',
        'boolean' => 'is_bool($v)',
        'integer' => 'is_int($v) || $v instanceof DXNInteger',
        'float' => 'is_float($v)',
        'nil' => '$v === null',
        'list' => '$v instanceof DXNList',
    ];

    /** @var list<string> the check methods, in the order they were made */
    private array $fns = [];

    /** @var array<string, string> each type's check method, by the type's key */
    private array $seen = [];

    private int $next = 1;

    /** @var array<string, int> each DXN constant's number, by its text */
    private array $constants = [];

    /** @var int the deprecated things numbered so far, for "once" */
    private int $slots = 0;

    /**
     * @param string $name the schema
     * @param list<Compiled> $versions its versions, in order
     * @param ClassInfo $class its classes and files
     * @param array<string, ClassInfo> $classes every schema's, for references
     * @param Registry $registry the tree's registry, for named types
     */
    public function __construct(
        private readonly string $name,
        private readonly array $versions,
        private readonly array $class,
        private readonly array $classes,
        private readonly Registry $registry,
    ) {
    }

    /**
     * The generated file.
     *
     * @throws Unsupported a form not generated yet, naming its version and field
     */
    public function source(): string
    {
        $latest = $this->versions[count($this->versions) - 1];
        foreach ($this->versions as $compiled) {
            if ($compiled->extras['entries'] !== []) {
                throw new Unsupported('typed extras (`* K => V`) are not generated yet', $compiled->version);
            }
        }
        $deprecated = $this->deprecatedVersions();
        $versionCode = array_map($this->versionCode(...), $this->versions);
        $upgrades = self::upgrades($this->versions);
        $hand = array_values(array_filter($upgrades, static fn (array $u): bool => $u['hand'] !== []));

        $owner = $this->owner();
        $user = $this->class['class'];
        $parent = "Abstract{$user}";
        $quoted = self::str($this->name);

        $body = implode("\n", [
            "    // dextrin:keep top\n    // dextrin:end top\n",
            "    /** The DXNS schema this class is. */\n    public const DXN_SCHEMA = {$quoted};\n",
            "    /** The schema's latest version, which the properties are. */\n    public const DXN_LATEST = {$latest->version};\n",
            '%CONSTANTS%',
            $this->constructor($latest),
            ...$this->withers($latest),
            $this->readCode($deprecated),
            $this->readStructCode($latest, $deprecated),
            $this->validateCode($latest),
            $this->toDxnCode($latest),
            ...$versionCode,
            ...array_map(fn (array $u): string => $this->upgradeCode($u), $upgrades),
            ...array_map(fn (array $u): string => $this->abstractUpgrade($u), $hand),
            ...($upgrades === [] ? [] : [$this->upgradeFromCode($upgrades)]),
            $this->buildCode($latest),
            ...$this->fns,
            "    // dextrin:keep bottom\n    // dextrin:end bottom\n",
        ]);
        $body = str_replace("%CONSTANTS%\n", $this->constantsCode(), $body);
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;

        $imports = [];
        foreach (self::IMPORTS as $short => $fqcn) {
            if (preg_match('/(?<![\\\\\w$])' . $short . '(?![\w\\\\])/', $body) === 1) {
                $imports[] = "use {$fqcn};";
            }
        }
        sort($imports);
        $uses = $imports === [] ? '' : implode("\n", $imports) . "\n\n";
        $generated = Php::GENERATED;
        $text = self::comment($this->name);

        return <<<PHP
            <?php

            {$generated} from the DXNS schema {$text}.
            // Regenerating rewrites it: do not edit it outside the dextrin:keep regions.
            // dextrin:schema {$this->name}

            declare(strict_types=1);

            namespace {$this->class['namespace']};

            {$uses}/**
             * The generated half of {@see {$user}}, the schema {$text}: its
             * properties are the latest version's fields, and it reads, checks and
             * upgrades every version with code of its own. {@see {$owner}::read()}
             * is where data comes in.
             *
             * @phpstan-consistent-constructor
             */
            abstract class {$parent}
            {
            {$body}}

            PHP;
    }

    // ---- the class's members ------------------------------------------------------------

    /** The user's class, fully qualified. */
    private function owner(): string
    {
        return '\\' . $this->class['fqcn'];
    }

    /**
     * Each version that is deprecated: its slot and reason, by version.
     *
     * @return array<int, array{0: int, 1: ?string}>
     */
    private function deprecatedVersions(): array
    {
        $out = [];
        foreach ($this->versions as $compiled) {
            $reason = Deprecations::reason($compiled->annotations);
            if ($reason !== null) {
                $out[$compiled->version] = [++$this->slots, $reason[1]];
            }
        }
        return $out;
    }

    private function constructor(Compiled $latest): string
    {
        $docs = [];
        $plain = [];
        $defaulted = [];
        foreach ($latest->fields as $field) {
            [$native, $doc] = $this->propertyType($field);
            $prop = self::property($field->name);
            $docs[] = "     * @param {$doc} \${$prop} the field {$field->name}";
            $default = $this->parameterDefault($field);
            $line = "        public readonly {$native} \${$prop}" . ($default === null ? '' : " = {$default}") . ',';
            if ($default === null) {
                $plain[] = $line;
            } else {
                $defaulted[] = $line;
            }
        }
        $params = implode("\n", [...$plain, ...$defaulted]);
        $docText = implode("\n", $docs);
        $text = self::comment($this->name);
        $open = $params === '' ? "    public function __construct()\n    {\n    }\n" : "    public function __construct(\n{$params}\n    ) {\n    }\n";
        return <<<PHP
                /**
                 * A {$text} with these fields, as its latest version has them.
                 * Nothing is checked: {@see self::validate()} checks.
                 *
            {$docText}
                 */
            {$open}
            PHP;
    }

    /** @return list<string> */
    private function withers(Compiled $latest): array
    {
        $out = [];
        foreach ($latest->fields as $field) {
            [$native, $doc] = $this->propertyType($field);
            $prop = self::property($field->name);
            $args = implode(', ', array_map(
                static fn (Field $f): string => self::property($f->name) . ': ' . ($f->name === $field->name ? '$' : '$this->') . self::property($f->name),
                $latest->fields,
            ));
            // `signed_up_at` is `withSignedUpAt`: a method in PHP's own case.
            $method = 'with' . implode('', array_map(ucfirst(...), explode('_', $prop)));
            $out[] = <<<PHP
                    /**
                     * A copy with the field {$field->name} changed; nothing is checked.
                     *
                     * @param {$doc} \${$prop} the new value
                     * @return static the copy
                     */
                    public function {$method}({$native} \${$prop}): static
                    {
                        return new static({$args});
                    }

                PHP;
        }
        return $out;
    }

    /** @param array<int, array{0: int, 1: ?string}> $deprecated */
    private function readCode(array $deprecated): string
    {
        $text = self::comment($this->name);
        $owner = $this->owner();
        return <<<PHP
                /**
                 * A decoded {$text} of any known version, read and upgraded to the
                 * latest, or one of this class, checked. What is deprecated warns as
                 * `\$deprecations` says -- `once` per deprecated thing (the default),
                 * `every`, `off`, or `error`, which refuses the value -- and
                 * `\$onDeprecated` receives each warning in place of
                 * `trigger_error(\$text, E_USER_DEPRECATED)` ({@see Runtime::reading()}).
                 *
                 * @param DXNStruct|self \$value the struct, or a value of this class
                 * @param string \$deprecations how often deprecated things warn
                 * @param callable|null \$onDeprecated receives each warning
                 * @return static the value, of {@see {$owner}}
                 * @throws InvalidData when it is refused, naming the field
                 * @throws \InvalidArgumentException for an unknown `\$deprecations`
                 */
                public static function read(DXNStruct|self \$value, string \$deprecations = 'once', ?callable \$onDeprecated = null): static
                {
                    return Runtime::reading(\$deprecations, \$onDeprecated, static function () use (\$value): static {
                        if (\$value instanceof static) {
                            \$value->validate();
                            return \$value;
                        }
                        if (!\$value instanceof DXNStruct) {
                            throw InvalidData::expected('a ' . self::DXN_SCHEMA, \$value);
                        }
                        return self::readStruct(\$value);
                    });
                }

            PHP;
    }

    /** @param array<int, array{0: int, 1: ?string}> $deprecated */
    private function readStructCode(Compiled $latest, array $deprecated): string
    {
        $notes = '';
        if ($deprecated !== []) {
            $entries = implode(', ', array_map(
                static fn (int $v, array $d): string => "{$v} => [{$d[0]}, " . self::str($d[1]) . ']',
                array_keys($deprecated),
                array_values($deprecated),
            ));
            $notes = <<<PHP
                        \$deprecated = [{$entries}];
                        foreach (array_unique([\$version, self::DXN_LATEST]) as \$at) {
                            if (isset(\$deprecated[\$at])) {
                                Runtime::deprecated(self::class, \$deprecated[\$at][0], self::DXN_SCHEMA, \$at, \$deprecated[\$at][1]);
                            }
                        }

                PHP;
        }
        $arms = '';
        foreach ($this->versions as $compiled) {
            $arms .= "            {$compiled->version} => " . $this->readAt($compiled) . ",\n";
        }
        $latestRead = $this->readAt($latest);
        $text = self::comment($this->name);
        return <<<PHP
                /**
                 * A struct of {$text}, read with the version it was written with --
                 * one newer than any known as the latest -- and upgraded.
                 *
                 * @throws InvalidData when it is refused
                 */
                private static function readStruct(DXNStruct \$value): static
                {
                    if (\$value->name !== self::DXN_SCHEMA) {
                        throw InvalidData::expected('a ' . self::DXN_SCHEMA, \$value);
                    }
                    \$version = \$value->version ?? self::DXN_LATEST;
            {$notes}        return match (\$version) {
            {$arms}            default => \$version > self::DXN_LATEST
                            ? {$latestRead}
                            : throw new InvalidData(self::DXN_SCHEMA . " version {\$version} is not known", [], 'schema/unknown-version'),
                    };
                }

            PHP;
    }

    /** The expression reading a struct `$value` written with `$compiled`'s version. */
    private function readAt(Compiled $compiled): string
    {
        $names = self::list(array_map(self::str(...), $compiled->fieldNames()));
        $fields = "self::fieldsV{$compiled->version}(Runtime::named(\$value, {$names}))";
        return count($this->versions) > 1
            ? "self::build(self::upgradeFrom({$compiled->version}, {$fields}))"
            : "self::build({$fields})";
    }

    private function validateCode(Compiled $latest): string
    {
        $text = self::comment($this->name);
        return <<<PHP
                /**
                 * Whether this value is a valid {$text} at the latest version: it
                 * throws when it is not. A property holding `null` is an absent field.
                 * The options are {@see self::read()}'s.
                 *
                 * @param string \$deprecations how often deprecated things warn
                 * @param callable|null \$onDeprecated receives each warning
                 * @throws InvalidData when it is not valid, naming the field
                 * @throws \InvalidArgumentException for an unknown `\$deprecations`
                 */
                public function validate(string \$deprecations = 'once', ?callable \$onDeprecated = null): void
                {
                    Runtime::reading(\$deprecations, \$onDeprecated, fn (): array => self::fieldsV{$latest->version}(Runtime::present({$this->fieldArray($latest)}, {$this->requiredList($latest)})));
                }

            PHP;
    }

    private function toDxnCode(Compiled $latest): string
    {
        return <<<PHP
                /**
                 * This value as a struct at the latest version; a property holding
                 * `null` is left out.
                 *
                 * @return DXNStruct the struct
                 */
                public function toDxn(): DXNStruct
                {
                    return DXNStruct::keyed(self::DXN_SCHEMA, Runtime::pairs({$this->fieldArray($latest)}, {$this->requiredList($latest)}), self::DXN_LATEST);
                }

            PHP;
    }

    /** `['name' => $this->name, ...]`: the properties by field name. */
    /** The names of a version's required fields, as a PHP list literal. */
    private function requiredList(Compiled $version): string
    {
        $names = [];
        foreach ($version->fields as $field) {
            if ($field->required) {
                $names[] = self::str($field->name);
            }
        }
        return '[' . implode(', ', $names) . ']';
    }

    private function fieldArray(Compiled $latest): string
    {
        return self::list(array_map(
            static fn (Field $f): string => self::str($f->name) . ' => $this->' . self::property($f->name),
            $latest->fields,
        ));
    }

    private function buildCode(Compiled $latest): string
    {
        $args = implode("\n", array_map(
            static fn (Field $f): string => '            ' . self::property($f->name) . ': $fields[' . self::str($f->name) . ']'
                . ($f->required || $f->default !== Field::NO_DEFAULT ? '' : ' ?? null') . ',',
            $latest->fields,
        ));
        $call = $args === '' ? 'new static()' : "new static(\n{$args}\n        )";
        return <<<PHP
                /**
                 * A value of this class from the latest version's fields, checked.
                 *
                 * @param array<string, mixed> \$fields the fields, by name
                 */
                private static function build(array \$fields): static
                {
                    return {$call};
                }

            PHP;
    }

    /** The PHP code a field's default is, or `null` for none. */
    private function defaultCode(Field $field): ?string
    {
        if ($field->default === Field::NO_DEFAULT) {
            return null;
        }
        if ($field->default instanceof Computed) {
            // Computed when a value is read, never when the code is written.
            return 'Field::filled(new Computed(' . self::str($field->default->kind) . '))';
        }
        return $this->constant(self::dxnText($field->default));
    }

    // ---- versions ---------------------------------------------------------------------

    private function versionCode(Compiled $compiled): string
    {
        $v = $compiled->version;
        $specs = [];
        foreach ($compiled->fields as $field) {
            try {
                $specs[] = '            ' . $this->spec($field) . ',';
            } catch (Unsupported $e) {
                throw new Unsupported("field {$field->name}: {$e->getMessage()}", $v);
            }
        }
        $specText = $specs === [] ? '[]' : "[\n" . implode("\n", $specs) . "\n        ]";
        $reason = Deprecations::reason($compiled->annotations);
        $why = $reason === null ? null : ($reason[1] ?? "version {$v} is deprecated");
        $tag = $why === null ? '' : "\n     * @deprecated " . self::comment($why);
        $attribute = $why === null ? '' : '    #[\Deprecated(message: ' . self::str($why) . ")]\n";
        $owner = $this->owner();
        // A closed schema (`- *`) refuses an undeclared field before any is checked.
        $closed = $compiled->extras['closed']
            ? '        Runtime::closed($fields, ' . self::list(array_map(self::str(...), $compiled->fieldNames())) . ");\n"
            : '';
        return <<<PHP
                /**
                 * Fields written with version {$v}: checked, defaults filled, upgraded to
                 * the latest, and made a {@see {$owner}}.
                 *
                 * @param array<string, mixed> \$fields the fields, by name
                 * @return static the value
                 * @throws InvalidData when a field is refused{$tag}
                 */
            {$attribute}    public static function readV{$v}(array \$fields): static
                {
                    return {$this->readFields($compiled)};
                }

                /**
                 * Fields as version {$v} says them: checked, defaults filled.
                 *
                 * @param array<string, mixed> \$fields the fields, by name
                 * @return array<string, mixed> the fields, checked
                 * @throws InvalidData when a field is refused{$tag}
                 */
            {$attribute}    public static function conformV{$v}(array \$fields): array
                {
                    return self::fieldsV{$v}(\$fields);
                }

                /**
                 * Version {$v}'s checks.
                 *
                 * @param array<string, mixed> \$fields
                 * @return array<string, mixed>
                 */
                private static function fieldsV{$v}(array \$fields): array
                {
            {$closed}        return Runtime::fields(\$fields, {$specText});
                }

            PHP;
    }

    private function readFields(Compiled $compiled): string
    {
        $fields = "self::fieldsV{$compiled->version}(\$fields)";
        return count($this->versions) > 1
            ? "self::build(self::upgradeFrom({$compiled->version}, {$fields}))"
            : "self::build({$fields})";
    }

    /** A field's `[name, required, check, default]` for {@see Runtime::fields()}. */
    private function spec(Field $field): string
    {
        // A field that must be absent (`:never`) needs no check: present, it is refused.
        $check = $field->type->form === 'never' ? 'null' : 'self::' . $this->typeFn($field->type) . '(...)';
        $default = $this->defaultCode($field);
        return '[' . self::str($field->name) . ', ' . ($field->required ? 'true' : 'false') . ", {$check}, "
            . ($default === null ? 'null' : "static fn (): mixed => {$default}") . ']';
    }

    // ---- upgrades --------------------------------------------------------------------

    /**
     * Between each version and the next: what moves, what is added with a
     * default, what is dropped, and what needs a hand-written step (a
     * changed type, a field made required, a required field with no
     * default added).
     *
     * @param list<Compiled> $versions
     * @return list<array{from: int, to: int, moves: list<array{0: string, 1: string}>, added: list<Field>, dropped: list<string>, hand: list<string>}>
     */
    private static function upgrades(array $versions): array
    {
        $out = [];
        for ($i = 0; $i + 1 < count($versions); $i++) {
            [$from, $to] = [$versions[$i], $versions[$i + 1]];
            $before = [];
            foreach ($from->fields as $f) {
                $before[$f->name] = $f;
            }
            $moves = [];
            $added = [];
            $hand = [];
            $kept = [];
            foreach ($to->fields as $field) {
                $old = $before[self::renamedFrom($field) ?? $field->name] ?? null;
                if ($old === null) {
                    if (!$field->required || $field->default !== Field::NO_DEFAULT) {
                        if ($field->default !== Field::NO_DEFAULT) {
                            $added[] = $field;
                        }
                    } else {
                        $hand[] = $field->name;
                    }
                    continue;
                }
                $kept[] = $old->name;
                if ($old->name !== $field->name) {
                    $moves[] = [$old->name, $field->name];
                }
                // A field whose type changed, or that became required, is
                // carried into the hand-written step, under its new name.
                if (self::meaning($old->type) !== self::meaning($field->type) || ($field->required && !$old->required)) {
                    $hand[] = $field->name;
                }
            }
            $dropped = array_values(array_diff($from->fieldNames(), $kept));
            $out[] = ['from' => $from->version, 'to' => $to->version, 'moves' => $moves, 'added' => $added, 'dropped' => $dropped, 'hand' => $hand];
        }
        return $out;
    }

    /** A field's former name, from its type's `meta`: `typespec.renamed-from`. */
    private static function renamedFrom(Field $field): ?string
    {
        if ($field->type->form !== 'doc') {
            return null;
        }
        $meta = $field->type->notes['meta'] ?? null;
        $pairs = match (true) {
            $meta instanceof DXNMap => $meta->pairs(),
            is_array($meta) => array_map(static fn (int|string $k, mixed $v): array => [MapBuilder::toDxnMapKey($k), $v], array_keys($meta), array_values($meta)),
            default => [],
        };
        foreach ($pairs as [$key, $value]) {
            $name = $key instanceof DXNKeyword ? $key->name : $key;
            if ($name === 'typespec.renamed-from' && is_string($value)) {
                return $value;
            }
        }
        return null;
    }

    /** A type as what it takes: its annotations and descriptions left out. */
    private static function meaning(TypeExpr $type): string
    {
        return serialize(self::undocumented($type));
    }

    private static function undocumented(TypeExpr $type): TypeExpr
    {
        if ($type->form === 'doc') {
            return self::undocumented($type->elem ?? TypeExpr::any());
        }
        $type = $type->mapTypes(self::undocumented(...));
        return $type->fields === null ? $type : $type->with(['fields' => array_map(
            static fn (Field $f): Field => new Field($f->name, $f->required, $f->type, $f->default, null),
            $type->fields,
        )]);
    }

    /** @param array{from: int, to: int, moves: list<array{0: string, 1: string}>, added: list<Field>, dropped: list<string>, hand: list<string>} $up */
    private function upgradeCode(array $up): string
    {
        $from = $up['from'];
        $lines = '';
        foreach ($up['moves'] as [$old, $new]) {
            $lines .= '        $fields = Runtime::move($fields, ' . self::str($old) . ', ' . self::str($new) . ");\n";
        }
        foreach ($up['dropped'] as $old) {
            $lines .= '        unset($fields[' . self::str($old) . "]);\n";
        }
        foreach ($up['added'] as $field) {
            $key = self::str($field->name);
            $lines .= "        if (!array_key_exists({$key}, \$fields)) {\n            \$fields[{$key}] = {$this->defaultCode($field)};\n        }\n";
        }
        $user = $this->class['class'];
        $note = $up['hand'] === []
            ? ''
            : "\n     * " . implode(', ', $up['hand']) . ": by hand, in upgradeV{$from}() of {$user}; the\n     * region below may prepare the fields first.";
        $tail = $up['hand'] === [] ? '$fields' : "static::upgradeV{$from}(\$fields)";
        return <<<PHP
                /**
                 * Fields of version {$from} as version {$up['to']} has them.{$note}
                 *
                 * @param array<string, mixed> \$fields the fields, by name
                 * @return array<string, mixed> the fields, upgraded
                 */
                public static function upgradeFromV{$from}(array \$fields): array
                {
            {$lines}        // dextrin:keep upgrade_v{$from}
                    // dextrin:end upgrade_v{$from}
                    return {$tail};
                }

            PHP;
    }

    /** @param array{from: int, to: int, moves: list<array{0: string, 1: string}>, added: list<Field>, dropped: list<string>, hand: list<string>} $up */
    private function abstractUpgrade(array $up): string
    {
        $fields = implode(', ', $up['hand']);
        $user = $this->class['class'];
        return <<<PHP
                /**
                 * Fields of version {$up['from']} as version {$up['to']} has them, where code can't
                 * do it: {$fields}. Written by hand in {$user}; {@see self::upgradeFromV{$up['from']}()}
                 * calls it after its region.
                 *
                 * @param array<string, mixed> \$fields the fields, by name
                 * @return array<string, mixed> the fields, upgraded
                 */
                abstract protected static function upgradeV{$up['from']}(array \$fields): array;

            PHP;
    }

    /** @param list<array{from: int, to: int, moves: list<array{0: string, 1: string}>, added: list<Field>, dropped: list<string>, hand: list<string>}> $upgrades */
    private function upgradeFromCode(array $upgrades): string
    {
        $steps = '';
        foreach ($upgrades as $up) {
            $steps .= "        if (\$version <= {$up['from']}) {\n            \$fields = self::upgradeFromV{$up['from']}(\$fields);\n        }\n";
        }
        return <<<PHP
                /**
                 * Fields of `\$version`, step by step, as the latest has them.
                 *
                 * @param array<string, mixed> \$fields
                 * @return array<string, mixed>
                 */
                private static function upgradeFrom(int \$version, array \$fields): array
                {
            {$steps}        return \$fields;
                }

            PHP;
    }

    // ---- constants ---------------------------------------------------------------------

    /** The code for a DXN constant written as `$text`, decoded once. */
    private function constant(string $text): string
    {
        $this->constants[$text] ??= count($this->constants) + 1;
        return "self::dxn({$this->constants[$text]}, " . self::str($text) . ')';
    }

    private function constantsCode(): string
    {
        if ($this->constants === []) {
            return '';
        }
        return <<<'PHP'
                /** @var array<int, mixed> the DXN constants, decoded, by number */
                private static array $dxn = [];

                /** A DXN constant written as `$text`, decoded the first time it is needed. */
                private static function dxn(int $at, string $text): mixed
                {
                    return self::$dxn[$at] ??= Runtime::value($text);
                }


            PHP;
    }

    // ---- types ------------------------------------------------------------------------

    /**
     * The name of the check method for `$type`, written once per distinct
     * type. A `{:doc}` that deprecates nothing is its type's.
     *
     * @throws Unsupported
     */
    private function typeFn(TypeExpr $type): string
    {
        if ($type->form === 'doc' && !$this->deprecates($type)) {
            return $this->typeFn($type->elem ?? TypeExpr::any());
        }
        if ($type->form === 'named') {
            return $this->namedFn($type->name ?? '');
        }
        $key = self::key($type);
        if (isset($this->seen[$key])) {
            return $this->seen[$key];
        }
        $name = 't' . $this->next++;
        $this->seen[$key] = $name;
        $body = $this->typeBody($type);
        $this->fns[] = $this->method($name, self::describe($type), $body);
        return $name;
    }

    private function namedFn(string $named): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $named, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $fn = 'named' . implode('', array_map(ucfirst(...), $words));
        $key = "named:{$named}";
        if (!isset($this->seen[$key])) {
            $this->seen[$key] = $fn;
            $type = $this->registry->fetchTypeAlias($named) ?? throw new Unsupported("the named type {$named} is not known");
            $inner = $this->typeFn($type);
            $this->fns[] = $this->method($fn, $named, "return self::{$inner}(\$v);");
        }
        return $fn;
    }

    private function method(string $name, string $what, string $body): string
    {
        $indented = implode("\n", array_map(static fn (string $l): string => $l === '' ? '' : "        {$l}", explode("\n", $body)));
        $what = self::comment($what);
        // `:any` takes everything: it throws nothing, and phpstan says so.
        $throws = $body === 'return $v;' ? '' : "\n     *\n     * @throws InvalidData when the value is not one";
        return <<<PHP
                /**
                 * A value of {$what}, checked.{$throws}
                 */
                private static function {$name}(mixed \$v): mixed
                {
            {$indented}
                }

            PHP;
    }

    private static function key(TypeExpr $type): string
    {
        try {
            return md5(serialize($type));
        } catch (\Throwable) {
            return 'object:' . spl_object_id($type);
        }
    }

    /**
     * The statements checking `$v` as `$type`.
     *
     * @throws Unsupported
     */
    private function typeBody(TypeExpr $type): string
    {
        $fn = fn (?TypeExpr $t): string => 'self::' . $this->typeFn($t ?? TypeExpr::any()) . '(...)';
        $call = fn (?TypeExpr $t): string => 'self::' . $this->typeFn($t ?? TypeExpr::any()) . '($v)';
        $variants = fn (): string => '[' . implode(', ', array_map($fn, $type->variants ?? [])) . ']';
        return match ($type->form) {
            'any' => 'return $v;',
            'never' => "throw new InvalidData('no value is allowed here');",
            'primitive' => $this->primitiveBody($type->name ?? ''),
            'doc' => $this->docBody($type),
            'nilable' => 'return $v === null ? null : ' . $call($type->elem) . ';',
            'refine' => $this->refineBody($type),
            'enum' => 'return Runtime::enum($v, ' . $this->constant(self::dxnText($type->literals ?? [])) . ');',
            'list-of' => 'return Runtime::listOf($v, ' . $fn($type->elem) . ');',
            'map-of' => 'return Runtime::mapOf($v, ' . $fn($type->key) . ', ' . $fn($type->value) . ');',
            'any-of' => "return Runtime::anyOf(\$v, {$variants()});",
            'one-of' => "return Runtime::oneOf(\$v, {$variants()});",
            'all-of' => "return Runtime::allOf(\$v, {$variants()});",
            'not' => 'return Runtime::not($v, ' . $fn($type->elem) . ');',
            'if' => 'return Runtime::if($v, ' . $fn($type->base) . ', ' . $fn($type->elem) . ', ' . $fn($type->otherwise) . ');',
            'reference' => $this->referenceBody($type),
            'shape' => $this->shapeBody($type),
            default => throw new Unsupported(self::formName($type) . ' is not generated yet'),
        };
    }

    /** A form as DXNS writes it: `{:tuple-of ...}`. */
    private static function formName(TypeExpr $type): string
    {
        return match ($type->form) {
            'apply' => "{:of {$type->name} ...}",
            'generic' => '{:generic ...}',
            'param' => "the generic parameter {$type->name}",
            default => "{:{$type->form} ...}",
        };
    }

    private function primitiveBody(string $name): string
    {
        $test = self::INLINE[$name] ?? null;
        return $test === null
            ? 'return Runtime::primitive(' . self::str($name) . ', $v);'
            : "return {$test} ? \$v : throw InvalidData::expected(" . self::str($name) . ', $v);';
    }

    private function deprecates(TypeExpr $doc): bool
    {
        return Deprecations::reason($doc->notes ?? []) !== null || $this->deprecatedValues($doc->notes ?? [], false) !== [];
    }

    /** A `{:doc}` that deprecates what it stands on, or some of its enum values: noted when a value goes through it. */
    private function docBody(TypeExpr $doc): string
    {
        $notes = $doc->notes ?? [];
        $lines = ['$v = self::' . $this->typeFn($doc->elem ?? TypeExpr::any()) . '($v);'];
        $reason = Deprecations::reason($notes);
        if ($reason !== null) {
            $title = $notes['title'] ?? null;
            $lines[] = 'Runtime::deprecated(self::class, ' . ++$this->slots . ', ' . self::str(is_string($title) ? $title : null) . ', null, ' . self::str($reason[1]) . ');';
        }
        $values = $this->deprecatedValues($notes, true);
        if ($values !== []) {
            $lines[] = 'Runtime::deprecatedValue(self::class, $v, [' . implode(', ', $values) . ']);';
        }
        $lines[] = 'return $v;';
        return implode("\n", $lines);
    }

    /**
     * An enum's deprecated values (`values:`), each `[literal, slot, why]`
     * as code, in the order of their DXN text.
     *
     * @param array<string, mixed> $notes
     * @return list<string>
     */
    private function deprecatedValues(array $notes, bool $number): array
    {
        $values = $notes['values'] ?? null;
        $pairs = match (true) {
            $values instanceof DXNMap => $values->pairs(),
            is_array($values) => array_map(static fn (int|string $k, mixed $v): array => [MapBuilder::toDxnMapKey($k), $v], array_keys($values), array_values($values)),
            default => [],
        };
        $found = [];
        foreach ($pairs as [$literal, $valueNotes]) {
            $reason = is_array($valueNotes) ? Deprecations::reason($valueNotes) : null;
            if ($reason !== null) {
                $found[self::dxnText($literal)] = $reason[1];
            }
        }
        ksort($found);
        $out = [];
        foreach ($found as $text => $why) {
            $out[] = $number
                ? '[' . $this->constant((string) $text) . ', ' . ++$this->slots . ', ' . self::str($why) . ']'
                : (string) $text;
        }
        return $out;
    }

    private function refineBody(TypeExpr $refine): string
    {
        $constraints = $refine->constraints ?? [];
        $unsupported = array_values(array_intersect(array_keys($constraints), TypeExpr::TYPE_KEYS));
        if ($unsupported !== []) {
            throw new Unsupported('refine keys not generated yet: ' . implode(', ', $unsupported));
        }
        $base = 'self::' . $this->typeFn($refine->base ?? TypeExpr::any()) . '($v)';
        $primitive = self::primitiveOf($refine->base);
        return "return Runtime::refine({$base}, " . $this->constant(self::dxnText($constraints)) . ', ' . self::str($primitive) . ');';
    }

    private static function primitiveOf(?TypeExpr $type): ?string
    {
        return match ($type?->form) {
            'primitive' => $type->name,
            'refine' => self::primitiveOf($type->base),
            'doc' => self::primitiveOf($type->elem),
            default => null,
        };
    }

    private function referenceBody(TypeExpr $reference): string
    {
        $name = $reference->name ?? '';
        if ($reference->version !== null) {
            throw new Unsupported("a reference pinned to a version ({$name}({$reference->version})) is not generated yet");
        }
        $class = $this->classes[$name] ?? throw new Unsupported("the schema {$name} is not known");
        $fqcn = '\\' . $class['fqcn'];
        return "return \$v instanceof DXNStruct || \$v instanceof {$fqcn} ? {$fqcn}::read(\$v) : throw InvalidData::expected("
            . self::str("a {$name}") . ', $v);';
    }

    private function shapeBody(TypeExpr $shape): string
    {
        $extras = $shape->extras ?? TypeExpr::openExtras();
        if ($extras['entries'] !== []) {
            throw new Unsupported('typed extras (`* K => V`) are not generated yet');
        }
        $specs = array_map(fn (Field $f): string => '    ' . $this->spec($f) . ',', $shape->fields ?? []);
        $list = $specs === [] ? '[]' : "[\n" . implode("\n", $specs) . "\n]";
        return "return Runtime::shape(\$v, {$list}, " . ($extras['closed'] ? 'true' : 'false') . ');';
    }

    // ---- PHP types --------------------------------------------------------------------

    /**
     * A field's property type: native, and as PHPDoc says it.
     *
     * @return array{0: string, 1: string}
     */
    private function propertyType(Field $field): array
    {
        if ($field->type->form === 'never') {
            return ['null', 'null'];
        }
        $types = $this->phpTypes($field->type);
        if ($types !== null && !$field->required && $field->default === Field::NO_DEFAULT) {
            $types[] = ['null', 'null'];
        }
        if ($types === null) {
            return ['mixed', 'mixed'];
        }
        $native = array_values(array_unique(array_column($types, 0)));
        $doc = array_values(array_unique(array_column($types, 1)));
        if (in_array('null', $native, true) && count($native) === 2) {
            $other = array_values(array_diff($native, ['null']));
            return ['?' . $other[0], implode('|', $doc)];
        }
        return [implode('|', $native), implode('|', $doc)];
    }

    /**
     * The PHP types a value of `$type` is, each `[native, PHPDoc]`; `null`
     * for `mixed`, where no native type is exact.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private function phpTypes(TypeExpr $type): ?array
    {
        $all = function (array $types): ?array {
            $out = [];
            foreach ($types as $t) {
                $each = $t === null ? null : $this->phpTypes($t);
                if ($each === null) {
                    return null;
                }
                array_push($out, ...$each);
            }
            return $out;
        };
        return match ($type->form) {
            'primitive' => self::PRIMITIVE_TYPES[$type->name ?? ''] ?? null,
            'doc', 'refine' => $this->phpTypes($type->form === 'doc' ? ($type->elem ?? TypeExpr::any()) : ($type->base ?? TypeExpr::any())),
            'nilable' => $all([$type->elem, TypeExpr::primitive('nil')]),
            'enum' => $this->literalTypes($type->literals ?? []),
            'any-of', 'one-of' => $all($type->variants ?? []),
            'if' => $all([$type->elem, $type->otherwise]),
            'reference' => isset($this->classes[$type->name ?? ''])
                ? [['\\' . $this->classes[$type->name ?? '']['fqcn'], '\\' . $this->classes[$type->name ?? '']['fqcn']]]
                : null,
            'list-of' => [['DXNList', 'DXNList']],
            'map-of', 'shape' => [['array', 'array<int|string, mixed>'], ['DXNMap', 'DXNMap']],
            default => null,
        };
    }

    /**
     * @param list<mixed> $literals
     * @return list<array{0: string, 1: string}>|null
     */
    private function literalTypes(array $literals): ?array
    {
        $out = [];
        foreach ($literals as $literal) {
            $type = match (true) {
                $literal instanceof DXNKeyword => ['DXNKeyword', 'DXNKeyword'],
                is_string($literal) => ['string', 'string'],
                is_int($literal) => ['int', 'int'],
                is_bool($literal) => ['bool', 'bool'],
                is_float($literal) => ['float', 'float'],
                $literal === null => ['null', 'null'],
                default => null,
            };
            if ($type === null) {
                return null;
            }
            $out[] = $type;
        }
        return $out === [] ? null : $out;
    }

    /** A constructor parameter's default as PHP code, where PHP can write it as one; `null` otherwise. */
    private function parameterDefault(Field $field): ?string
    {
        if ($field->default === Field::NO_DEFAULT) {
            return $field->required ? null : 'null';
        }
        $value = $field->default;
        return match (true) {
            $value instanceof DXNKeyword => 'new DXNKeyword(' . self::str($value->name) . ')',
            is_string($value), is_int($value), is_bool($value) => var_export($value, true),
            is_float($value) && is_finite($value) => var_export($value, true),
            $value === null => 'null',
            default => null,
        };
    }

    // ---- text --------------------------------------------------------------------------

    /** A field name as a property: as it is where PHP allows it, `first-name` as `firstName`. */
    public static function property(string $field): string
    {
        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $field) === 1) {
            return $field;
        }
        $words = preg_split('/[^A-Za-z0-9_\x80-\xff]+/', $field, -1, PREG_SPLIT_NO_EMPTY) ?: ['field'];
        $name = $words[0] . implode('', array_map(ucfirst(...), array_slice($words, 1)));
        return ctype_digit($name[0]) ? "_{$name}" : $name;
    }

    /** A PHP string literal; `null` for `null`. */
    private static function str(?string $text): string
    {
        return $text === null ? 'null' : var_export($text, true);
    }

    /** @param list<string> $items */
    private static function list(array $items): string
    {
        return '[' . implode(', ', $items) . ']';
    }

    /** Text safe inside a doc comment. */
    private static function comment(string $text): string
    {
        return str_replace(['*/', "\n"], ['*\/', ' '], $text);
    }

    private static function dxnText(mixed $value): string
    {
        try {
            return Dextrin::encode($value);
        } catch (\Throwable $e) {
            throw new Unsupported('a value with no DXN text: ' . $e->getMessage());
        }
    }

    /** A type as DXNS writes it, short: what a check method's comment says it checks. */
    private static function describe(TypeExpr $type): string
    {
        $d = static fn (?TypeExpr $t): string => $t === null ? ':any' : self::describe($t);
        $many = static fn (?array $ts): string => implode(' ', array_map($d, $ts ?? []));
        $text = static function (mixed $value): string {
            try {
                return Dextrin::encode($value);
            } catch (\Throwable) {
                return '...';
            }
        };
        return match ($type->form) {
            'any', 'never' => ":{$type->form}",
            'primitive' => ":{$type->name}",
            'reference', 'named' => (string) $type->name,
            'doc' => '{:doc ' . $d($type->elem) . ' ...}',
            'nilable', 'list-of', 'not' => "{:{$type->form} " . $d($type->elem) . '}',
            'map-of' => '{:map-of ' . $d($type->key) . ' ' . $d($type->value) . '}',
            'refine' => '{:refine ' . $d($type->base) . ' ' . $text($type->constraints ?? []) . '}',
            'enum' => '{:enum ' . implode(' ', array_map($text, $type->literals ?? [])) . '}',
            'any-of', 'one-of', 'all-of' => "{:{$type->form} " . $many($type->variants) . '}',
            'if' => '{:if ' . $d($type->base) . ' ' . $d($type->elem) . ' ' . $d($type->otherwise) . '}',
            'shape' => $type->name ?? ('%shape{' . implode(' ', array_map(
                static fn (Field $f): string => $f->name . ($f->required ? '' : '?') . ': ' . self::describe($f->type),
                $type->fields ?? [],
            )) . '}'),
            default => "{:{$type->form} ...}",
        };
    }
}
