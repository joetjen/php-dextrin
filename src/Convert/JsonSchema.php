<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Convert;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\MapBuilder;
use JOetjen\Dextrin\Schema\Format;
use JOetjen\Dextrin\Value\DXNInteger;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNRegex;
use JOetjen\Dextrin\Value\DXNStruct;
use JOetjen\Dextrin\Value\DXNSymbol;
use JOetjen\Dextrin\Value\DXNTuple;

/**
 * JSON Schema to DXNS (T674 of dextrin's design/json-schema.md): drafts
 * 04, 06, 07, 2019-09 and 2020-12, and MongoDB's `$jsonSchema` with
 * `bsonType`. A port of `Dextrin.Convert.JsonSchema`.
 *
 * {@see self::toDxns()} takes one or more schema documents and answers the
 * DXNS document as a DXN value -- a map from each name to its type -- and
 * the list of what it could not say exactly.
 *
 * The mapping follows the decisions of design/json-schema.md:
 *
 * - an object schema becomes a `%shape` (plain maps, as JSON data is), or
 *   a `%schema` for a named definition with `structs`;
 * - a string with a date or time, uuid or uri format becomes that DXN type
 *   (logical types); any other known format is `format:`, an unknown one
 *   is kept in `meta`;
 * - every keyword applies only to values of its own kind, as JSON Schema
 *   says: a schema with no `type` checks a string's keywords on strings
 *   only, with `{:if :string ...}`;
 * - `patternProperties`, `additionalProperties` and `propertyNames` become
 *   `* K => V` entries and `- *`; `unevaluatedProperties` and
 *   `unevaluatedItems` are worked out where it can be told from the schema
 *   alone;
 * - references stay within the documents given: nothing is fetched.
 *
 * A JSON document is read with {@see Json::decode()}: an object is a
 * {@see JsonObject}, keeping its keys' order and telling `{}` from `[]`.
 *
 * @phpstan-type Inexact array{path: string, why: string}
 * @phpstan-type Ctx array{base: string, pointer: string, top: ?string, path: string}
 * @phpstan-type Entry array{node: mixed, own: string, base: string, pointer: string}
 * @phpstan-type Evaluated array{0: 'all'}|array{0: 'dynamic', 1: string}|array{0: 'static', 1: array<string, true>, 2: list<string>}
 * @phpstan-type ItemsEvaluated array{0: 'all'}|array{0: 'dynamic', 1: string}|array{0: 'static', 1: int, 2: list<array{0: mixed, 1: Ctx}>}
 */
final class JsonSchema
{
    private const DRAFTS = [
        'http://json-schema.org/draft-04/schema' => '04',
        'http://json-schema.org/draft-06/schema' => '06',
        'http://json-schema.org/draft-07/schema' => '07',
        'https://json-schema.org/draft/2019-09/schema' => '2019-09',
        'https://json-schema.org/draft/2020-12/schema' => '2020-12',
    ];

    private const KINDS = ['string', 'number', 'array', 'object', 'boolean', 'null'];
    private const STRING_KEYS = ['minLength', 'maxLength', 'pattern', 'format', 'contentEncoding', 'contentMediaType', 'contentSchema'];
    private const NUMBER_KEYS = ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'];
    private const ARRAY_KEYS = ['items', 'prefixItems', 'additionalItems', 'minItems', 'maxItems', 'uniqueItems', 'contains', 'minContains', 'maxContains'];
    private const OBJECT_KEYS = ['properties', 'required', 'additionalProperties', 'patternProperties', 'propertyNames', 'minProperties', 'maxProperties'];

    /** A date or time, uuid or uri format on a string is that DXN type (logical types). */
    private const LOGICAL = [
        'date-time' => 'datetime',
        'date' => 'date',
        'time' => 'time',
        'duration' => 'duration',
        'uuid' => 'uuid',
        'uri' => 'uri',
    ];

    private const FORMATS = ['date-time' => 'datetime'];

    /** The annotations a `{:doc}` takes, by their JSON Schema names. */
    private const NOTES = [
        'deprecated' => 'deprecated',
        'description' => 'description',
        'examples' => 'examples',
        'readOnly' => 'read-only',
        'title' => 'title',
        'writeOnly' => 'write-only',
    ];

    private const KNOWN = [
        'type', 'enum', 'const', 'allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else', '$ref', '$recursiveRef', '$dynamicRef',
        '$schema', '$id', 'id', '$anchor', '$dynamicAnchor', '$recursiveAnchor', '$defs', 'definitions', '$comment', '$vocabulary',
        'dependentRequired', 'dependentSchemas', 'dependencies', 'unevaluatedProperties', 'unevaluatedItems',
        'default', 'bsonType', 'discriminator', 'title', 'description', 'examples', 'readOnly', 'writeOnly', 'deprecated',
        ...self::STRING_KEYS, ...self::NUMBER_KEYS, ...self::ARRAY_KEYS, ...self::OBJECT_KEYS,
    ];

    /** The keywords whose values are data, not schemas: no index or reference walks into them. */
    private const DATA = ['enum', 'const', 'examples', 'default'];

    private string $draft;
    private bool $mongo;
    private bool $structs;
    private bool $assertFormats;

    /** @var array<string, Entry> every schema reachable by a reference, by node key */
    private array $nodes = [];

    /** @var array<string, string> every URI a reference may name, to its node key */
    private array $ids = [];

    /** @var list<array{0: string, 1: string}> each document's place and base */
    private array $roots = [];

    /** @var array<string, string> the name of each schema that has one, by node key */
    private array $names = [];

    /** @var array<string, true> the names given out */
    private array $taken = [];

    /** @var list<Inexact> */
    private array $inexact = [];

    private function __construct()
    {
    }

    // ---- the API ---------------------------------------------------------------

    /**
     * JSON text decoded with each object's key order kept, which the
     * converter needs: properties become fields in the order written.
     *
     * @param string $text the JSON text
     * @return mixed a {@see JsonObject} for an object, a list for an array
     * @throws JsonSchemaError for text that is not JSON
     */
    public static function decodeJson(string $text): mixed
    {
        return Json::decode($text);
    }

    /**
     * Converts `$sources`, the first being the root, to a DXNS document.
     * Each source is `[where, schema]`: where it came from (a path or a
     * URI, which relative references resolve against when it has no
     * `$id`), and its JSON text or a value {@see self::decodeJson()} read.
     *
     * Options: `name` (the root's name; otherwise its `title`, otherwise
     * `Root`), `draft` (`"04"`, `"06"`, `"07"`, `"2019-09"` or
     * `"2020-12"`, for documents with no `$schema`; default 2020-12),
     * `mongo` (read the schema as MongoDB's; also when `bsonType` appears
     * anywhere), `structs` (write named object schemas as `%schema`),
     * `assertFormats` (check `format` and the content keywords in 2019-09
     * and 2020-12 too, where they are annotations).
     *
     * @param list<array{0: string, 1: mixed}>|array{0: string, 1: mixed} $sources one source or several
     * @param array{name?: ?string, draft?: ?string, mongo?: bool, structs?: bool, assertFormats?: bool} $options
     * @return array{document: array<string, mixed>, inexact: list<Inexact>} the document, a map
     *         from each name to its type, and what is not exact
     * @throws JsonSchemaError for a schema that cannot be read: a reference to a document not
     *         given, an unknown draft, a value that is no schema
     */
    public static function toDxns(array $sources, array $options = []): array
    {
        $docs = array_map(static function (mixed $source): array {
            if (!is_array($source) || !is_string($source[0] ?? null) || !array_key_exists(1, $source)) {
                throw new JsonSchemaError('a source is [where, schema]');
            }
            [$where, $schema] = [$source[0], $source[1]];
            if (is_string($schema)) {
                try {
                    $schema = Json::decode($schema);
                } catch (JsonSchemaError $err) {
                    throw new JsonSchemaError("{$where}: {$err->getMessage()}");
                }
            }
            return [$where, $schema];
        }, isset($sources[0]) && is_string($sources[0]) ? [$sources] : $sources);
        if ($docs === []) {
            throw new JsonSchemaError('no schema to convert');
        }

        $self = new self();
        $self->draft = self::draftOf($docs[0][1], $options['draft'] ?? null);
        $self->mongo = ($options['mongo'] ?? false) || array_reduce($docs, static fn (bool $any, array $doc): bool => $any || self::bson($doc[1]), false);
        $self->structs = $options['structs'] ?? false;
        // Formats and content keywords check values before 2019-09; from
        // then on they are annotations, unless asked otherwise.
        $self->assertFormats = ($options['assertFormats'] ?? false) || in_array($self->draft, ['04', '06', '07'], true);
        $self->index($docs);

        $root = $docs[0][1];
        if ($root instanceof JsonObject && is_string($root->get('$schema')) && self::draftFor($root->get('$schema')) === null) {
            $self->inexact[] = ['path' => '#/$schema', 'why' => "unknown metaschema {$root->get('$schema')}, read as draft {$self->draft}"];
        }
        $self->nameTargets($docs, $options['name'] ?? null);
        return $self->convertAll();
    }

    /**
     * A JSON value as the DXN value it is: an object a map with string
     * keys, an array a list, `null` nil, a number or a string itself.
     *
     * @param mixed $value a value {@see self::decodeJson()} read
     * @return mixed the DXN value
     */
    public static function jsonValue(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            return MapBuilder::build(
                array_map(static fn (array $pair): array => [$pair[0], self::jsonValue($pair[1])], $value->pairs()),
                static fn (string $message): \Throwable => new JsonSchemaError($message),
            );
        }
        if (is_array($value)) {
            return new DXNList(array_map(self::jsonValue(...), $value));
        }
        return $value;
    }

    /**
     * A JSON Schema (ECMA-262) pattern as a DXN regex: `\d` and `\w` are
     * ASCII there, `$` matches only at the very end, `\uXXXX` is a code
     * point. The regex reads code points (DXN's `u` flag), as ECMA-262
     * with `u` does: `.` and `\p{...}` take a whole character.
     *
     * @internal
     * @param string $pattern the ECMA-262 pattern
     * @return DXNRegex|string the regex, or why it is none
     */
    public static function regex(string $pattern): DXNRegex|string
    {
        $source = self::ecmaToPcre($pattern);
        if (!str_starts_with($source, '/')) {
            return $source;
        }
        try {
            $regex = Dextrin::decode('@regex(u) ' . Dextrin::encode(substr($source, 1)));
        } catch (\Throwable $err) {
            return $err->getMessage();
        }
        return $regex instanceof DXNRegex ? $regex : 'not a regex DXN reads';
    }

    // ---- reading -----------------------------------------------------------------

    private static function normalUri(string $uri): string
    {
        $uri = rtrim($uri, '#');
        return str_starts_with($uri, 'https://json-schema.org/draft-0')
            ? 'http://json-schema.org/draft-0' . substr($uri, strlen('https://json-schema.org/draft-0'))
            : $uri;
    }

    private static function draftFor(string $uri): ?string
    {
        return self::DRAFTS[self::normalUri($uri)] ?? null;
    }

    private static function draftOf(mixed $root, ?string $asked): string
    {
        if ($root instanceof JsonObject && is_string($root->get('$schema'))) {
            $draft = self::draftFor($root->get('$schema'));
            if ($draft !== null) {
                return $draft;
            }
            // A metaschema of its own: read as the draft asked for, and
            // said so.
        }
        $draft = $asked ?? '2020-12';
        if (!in_array($draft, self::DRAFTS, true)) {
            throw new JsonSchemaError("unknown draft {$draft}: 04, 06, 07, 2019-09 or 2020-12");
        }
        return $draft;
    }

    private static function bson(mixed $value): bool
    {
        if ($value instanceof JsonObject) {
            if ($value->has('bsonType')) {
                return true;
            }
            foreach ($value->pairs() as [, $v]) {
                if (self::bson($v)) {
                    return true;
                }
            }
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if (self::bson($v)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ---- the index: every schema by URI and pointer -------------------------------

    private static function key(string $base, string $fragment): string
    {
        return $base . "\0" . $fragment;
    }

    /** @return array{0: string, 1: string} a node key's base and fragment */
    private static function unkey(string $key): array
    {
        $at = strrpos($key, "\0");
        return [substr($key, 0, (int) $at), substr($key, (int) $at + 1)];
    }

    /**
     * Every subschema reachable by a reference: by its document's base and
     * JSON pointer, by its own `$id`, and by its `$anchor`.
     *
     * @param list<array{0: string, 1: mixed}> $docs
     */
    private function index(array $docs): void
    {
        foreach ($docs as [$where, $root]) {
            if (!$root instanceof JsonObject && !is_bool($root)) {
                throw new JsonSchemaError("{$where}: a schema is an object or a boolean");
            }
            $base = $this->resourceId($root, self::baseOf($where));
            $this->roots[] = [$where, $base];
            // The URI a document was read from names it too, whatever its $id.
            if ($base !== self::baseOf($where)) {
                $this->ids[self::baseOf($where)] = self::key($base, '#');
            }
            if (is_bool($root)) {
                $this->nodes[self::key($base, '#')] = ['node' => $root, 'own' => $base, 'base' => $base, 'pointer' => ''];
                $this->ids[$base] = self::key($base, '#');
            } else {
                $this->walkIndex($root, $base, '', []);
            }
        }
    }

    private static function baseOf(string $where): string
    {
        return str_contains($where, '://') ? self::stripFragment($where) : 'file:///' . $where;
    }

    /**
     * The base a schema's `$id` gives what is below it. Before 2019-09 a
     * `$ref` hides its siblings, `$id` included; and an `$id` that is only
     * a fragment, `#foo`, is an anchor, not a new base.
     */
    private function resourceId(mixed $node, string $base): string
    {
        if (!$node instanceof JsonObject) {
            return $base;
        }
        if ($node->has('$ref') && in_array($this->draft, ['04', '06', '07'], true)) {
            return $base;
        }
        $id = $node->get('$id');
        if (is_string($id)) {
            return str_starts_with($id, '#') ? $base : self::stripFragment(self::resolveUri($base, $id));
        }
        $id = $node->get('id');
        if (is_string($id)) {
            return $node->has('$schema') || str_contains($id, '/') || !str_starts_with($id, '#')
                ? self::stripFragment(self::resolveUri($base, $id))
                : $base;
        }
        return $base;
    }

    /**
     * Indexes `$node` and everything below it. `$outer` holds the enclosing
     * resources' bases with this schema's pointer from each: a JSON pointer
     * may cross into an embedded resource, so the schema is found from
     * every one of them.
     *
     * @param list<array{0: string, 1: string}> $outer
     */
    private function walkIndex(mixed $node, string $base, string $pointer, array $outer): void
    {
        if ($node instanceof JsonObject) {
            $own = $this->resourceId($node, $base);
            $key = self::key($own, $own === $base ? '#' . $pointer : '#');
            $entry = ['node' => $node, 'own' => $own, 'base' => $base, 'pointer' => $pointer];
            $this->nodes[self::key($base, '#' . $pointer)] = $entry;
            foreach ($outer as [$b, $p]) {
                $this->nodes[self::key($b, '#' . $p)] ??= $entry;
            }
            $this->nodes[$key] = $entry;
            // A document's root, or a schema with an `$id` of its own, is
            // what its URI names; a schema inside it is not.
            if ($own !== $base || $pointer === '') {
                $this->ids[$own] = $key;
            }
            foreach (['$anchor', '$dynamicAnchor'] as $anchor) {
                if (is_string($node->get($anchor))) {
                    $this->ids[$own . '#' . $node->get($anchor)] = $key;
                }
            }
            foreach (['id', '$id'] as $kw) {
                $id = $node->get($kw);
                if (!is_string($id)) {
                    continue;
                }
                if (str_starts_with($id, '#') && $id !== '#') {
                    $this->ids[$own . $id] = $key;
                } else {
                    [, $fragment] = self::splitFragment(self::resolveUri($base, $id));
                    if ($fragment !== null && $fragment !== '') {
                        $this->ids[$own . '#' . $fragment] = $key;
                    }
                }
            }
            // Below a schema with an `$id` of its own, pointers start again
            // from it.
            [$childBase, $childPointer] = $own !== $base ? [$own, ''] : [$base, $pointer];
            $childOuter = $own !== $base ? [[$base, $pointer], ...$outer] : $outer;
            foreach (self::sortedPairs($node) as [$k, $v]) {
                if (!in_array($k, self::DATA, true)) {
                    $this->walkIndex($v, $childBase, $childPointer . '/' . self::escape($k), self::deeper($childOuter, $k));
                }
            }
            return;
        }
        if (is_array($node)) {
            foreach ($node as $i => $v) {
                $this->walkIndex($v, $base, $pointer . '/' . $i, self::deeper($outer, (string) $i));
            }
            return;
        }
        // A boolean schema has no keywords, but a reference may name it.
        if (is_bool($node)) {
            $entry = ['node' => $node, 'own' => $base, 'base' => $base, 'pointer' => $pointer];
            foreach ([[$base, $pointer], ...$outer] as [$b, $p]) {
                $this->nodes[self::key($b, '#' . $p)] ??= $entry;
            }
        }
    }

    /**
     * An object's members in the order its keys sort -- the order the
     * reference walks a small map in.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private static function sortedPairs(JsonObject $node): array
    {
        $pairs = $node->pairs();
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        return $pairs;
    }

    /**
     * @param list<array{0: string, 1: string}> $outer
     * @return list<array{0: string, 1: string}>
     */
    private static function deeper(array $outer, string $segment): array
    {
        return array_map(static fn (array $o): array => [$o[0], $o[1] . '/' . self::escape($segment)], $outer);
    }

    private static function escape(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    private static function unescape(string $segment): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
    }

    private static function stripFragment(string $uri): string
    {
        return explode('#', $uri, 2)[0];
    }

    /** @return array{0: string, 1: ?string} */
    private static function splitFragment(string $uri): array
    {
        $parts = explode('#', $uri, 2);
        return [$parts[0], $parts[1] ?? null];
    }

    private static function resolveUri(string $base, string $ref): string
    {
        if ($ref === '') {
            return $base;
        }
        if (str_starts_with($ref, '#')) {
            return $base . $ref;
        }
        return Uri::merge($base, $ref) ?? $ref;
    }

    /**
     * The node a reference names, as `[key, node]`.
     *
     * @return array{0: string, 1: mixed}
     */
    private function target(string $base, string $ref): array
    {
        $uri = self::resolveUri($base, $ref);
        [$doc, $fragment] = self::splitFragment($uri);
        $found = match (true) {
            $fragment === null, $fragment === '' => $this->ids[$doc] ?? null,
            str_starts_with($fragment, '/') => $this->pointerTarget($doc, $fragment),
            default => $this->ids[$doc . '#' . $fragment] ?? null,
        };
        if ($found === null || !isset($this->nodes[$found])) {
            throw new JsonSchemaError("\$ref {$ref} names no schema among those given (resolved: {$uri})");
        }
        return [$found, $this->nodes[$found]['node']];
    }

    private function pointerTarget(string $doc, string $fragment): ?string
    {
        $segments = array_slice(explode('/', $fragment), 1);
        $pointer = implode('/', array_map(static fn (string $s): string => self::escape(self::unescape($s)), $segments));
        $key = self::key($doc, '#/' . $pointer);
        $id = $this->ids[$doc] ?? null;
        if ($id !== null) {
            [$base, $prefix] = self::unkey($id);
            if ($prefix !== '#') {
                $joined = self::key($base, $prefix . '/' . $pointer);
                return isset($this->nodes[$joined]) ? $joined : $key;
            }
        }
        return isset($this->nodes[$key]) ? $key : null;
    }

    // ---- naming ---------------------------------------------------------------------

    /**
     * Every schema that gets a name of its own: each document's root, its
     * `$defs` (`definitions`) entries, and whatever a reference names.
     *
     * @param list<array{0: string, 1: mixed}> $docs
     */
    private function nameTargets(array $docs, ?string $rootName): void
    {
        foreach ($this->roots as $i => [$where, $base]) {
            $key = $this->ids[$base];
            $node = $this->nodes[$key]['node'];
            $name = $i === 0 ? ($rootName ?? self::titleName($node) ?? 'Root') : (self::titleName($node) ?? self::fileName($where));
            $this->name($key, $name);
        }
        foreach ($docs as [$where, $root]) {
            $base = $this->resourceId($root, self::baseOf($where));
            foreach (['$defs', 'definitions'] as $defs) {
                $entries = $root instanceof JsonObject ? $root->get($defs) : null;
                if ($entries instanceof JsonObject) {
                    foreach ($entries->keys() as $k) {
                        $this->name(self::key($base, '#/' . $defs . '/' . self::escape($k)), $k);
                    }
                }
            }
        }
        // A reference to a schema with no name yet names it from its path.
        foreach ($docs as [$where, $root]) {
            $this->collectRefs($root, $this->resourceId($root, self::baseOf($where)));
        }
    }

    private static function titleName(mixed $node): ?string
    {
        $title = $node instanceof JsonObject ? $node->get('title') : null;
        return is_string($title) && $title !== '' ? self::identifier($title) : null;
    }

    private static function fileName(string $where): string
    {
        return self::identifier(explode('.', basename($where))[0]);
    }

    /** A name for `$key`, made an identifier and unique. */
    private function name(string $key, string $wanted): void
    {
        if (isset($this->names[$key])) {
            return;
        }
        $base = self::identifier($wanted);
        $name = $base;
        for ($i = 2; isset($this->taken[$name]); $i++) {
            $name = $base . $i;
        }
        $this->names[$key] = $name;
        $this->taken[$name] = true;
    }

    /**
     * `$text` as an identifier: characters one can't hold become `_`, and
     * one that would not begin with a letter or `_` begins with `T`.
     *
     * @internal
     */
    public static function identifier(string $text): string
    {
        $cleaned = trim((string) preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $text), '_');
        return match (true) {
            $cleaned === '' => 'T',
            preg_match('/^[\p{L}_]/u', $cleaned) === 1 => $cleaned,
            default => 'T' . $cleaned,
        };
    }

    private function collectRefs(mixed $node, string $base): void
    {
        if ($node instanceof JsonObject) {
            $base = $this->resourceId($node, $base);
            foreach (['$ref', '$recursiveRef', '$dynamicRef'] as $kw) {
                $ref = $node->get($kw);
                if (is_string($ref)) {
                    [$key] = $this->target($base, $ref);
                    $this->name($key, $this->pathName($key));
                }
            }
            foreach (self::sortedPairs($node) as [$k, $v]) {
                if (!in_array($k, self::DATA, true)) {
                    $this->collectRefs($v, $base);
                }
            }
        } elseif (is_array($node)) {
            foreach ($node as $v) {
                $this->collectRefs($v, $base);
            }
        }
    }

    private function pathName(string $key): string
    {
        [$base, $fragment] = self::unkey($key);
        $rootKey = $this->ids[$base] ?? null;
        $root = $rootKey === null ? 'T' : ($this->names[$rootKey] ?? 'T');
        $parts = array_filter(
            array_map(self::unescape(...), array_values(array_filter(explode('/', substr($fragment, 1)), static fn (string $s): bool => $s !== ''))),
            static fn (string $s): bool => !in_array($s, ['properties', 'items', '$defs', 'definitions'], true),
        );
        return implode('', [$root, ...array_map(self::capitalize(...), $parts)]);
    }

    /** The first character upper case and the rest lower case, as Elixir's `String.capitalize/1`. */
    private static function capitalize(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_strtolower(mb_substr($text, 1));
    }

    // ---- converting ------------------------------------------------------------------

    /** @return array{document: array<string, mixed>, inexact: list<Inexact>} */
    private function convertAll(): array
    {
        $named = $this->names;
        uksort($named, fn (string $a, string $b): int => strcmp($named[$a], $named[$b]));
        $document = [];
        foreach ($named as $key => $name) {
            $entry = $this->nodes[$key];
            $ctx = ['base' => $entry['base'], 'pointer' => $entry['pointer'], 'top' => $key, 'path' => '#' . $entry['pointer']];
            $type = $this->convert($entry['node'], $ctx);
            $document[$name] = $this->structs ? self::asStruct($type) : $type;
        }
        return ['document' => $document, 'inexact' => $this->inexact];
    }

    /** @param Ctx $ctx */
    private function noteInexact(array $ctx, string $why): void
    {
        $this->inexact[] = ['path' => $ctx['path'], 'why' => $why];
    }

    /**
     * @param Ctx $ctx
     * @return Ctx
     */
    private static function at(array $ctx, string $segment): array
    {
        return [
            'base' => $ctx['base'],
            'pointer' => $ctx['pointer'] . '/' . self::escape($segment),
            'top' => null,
            'path' => $ctx['path'] . '/' . self::escape($segment),
        ];
    }

    /**
     * A subschema: by name when it has one of its own, otherwise in place.
     *
     * @param Ctx $ctx
     */
    private function sub(mixed $node, array $ctx): mixed
    {
        $key = self::key($ctx['base'], '#' . $ctx['pointer']);
        if (isset($this->names[$key]) && $key !== $ctx['top']) {
            return new DXNSymbol($this->names[$key]);
        }
        return $this->convert($node, $ctx);
    }

    /** @param Ctx $ctx */
    private function convert(mixed $node, array $ctx): mixed
    {
        if ($node === true) {
            return self::kw('any');
        }
        if ($node === false) {
            return self::kw('never');
        }
        if (!$node instanceof JsonObject) {
            throw new JsonSchemaError("{$ctx['path']}: a schema is an object or a boolean");
        }
        // Below a schema with an `$id` of its own, pointers start again
        // from it, as the index has them.
        $own = $this->resourceId($node, $ctx['base']);
        if ($own !== $ctx['base']) {
            $ctx = ['base' => $own, 'pointer' => '', 'top' => $ctx['top'], 'path' => $ctx['path']];
        }
        if ($node->has('$ref') && in_array($this->draft, ['04', '06', '07'], true)) {
            // Before 2019-09 a $ref stands alone: its siblings are ignored.
            return $this->ref($node->get('$ref'), $ctx);
        }
        return $this->annotated(self::allOf($this->parts($node, $ctx)), $node, $ctx);
    }

    /** @param Ctx $ctx */
    private function ref(mixed $ref, array $ctx): mixed
    {
        if (!is_string($ref)) {
            throw new JsonSchemaError("{$ctx['path']}: a \$ref is a string");
        }
        [$key, $node] = $this->target($ctx['base'], $ref);
        if (isset($this->names[$key])) {
            return new DXNSymbol($this->names[$key]);
        }
        $entry = $this->nodes[$key];
        return $this->convert($node, ['base' => $entry['base'], 'pointer' => $entry['pointer'], 'top' => $ctx['top'], 'path' => $ctx['path']]);
    }

    /** @param list<mixed> $parts */
    private static function allOf(array $parts): mixed
    {
        return match (count($parts)) {
            0 => self::kw('any'),
            1 => $parts[0],
            default => self::tup([self::kw('all-of'), ...$parts]),
        };
    }

    /** @param list<mixed> $types */
    private static function anyOf(array $types): mixed
    {
        return count($types) === 1 ? $types[0] : self::tup([self::kw('any-of'), ...$types]);
    }

    /**
     * The conjuncts a schema object says: its kinds, its values, its
     * combinators and its references.
     *
     * @param Ctx $ctx
     * @return list<mixed>
     */
    private function parts(JsonObject $node, array $ctx): array
    {
        return [
            ...$this->refPart($node, $ctx),
            ...$this->kindPart($node, $ctx),
            ...self::enumPart($node),
            ...$this->combinatorParts($node, $ctx),
            ...$this->conditionalPart($node, $ctx),
            ...$this->dependentParts($node, $ctx),
            ...$this->unevaluatedPropsPart($node, $ctx),
            ...$this->unevaluatedItemsPart($node, $ctx),
            ...$this->dynamicPart($node, $ctx),
        ];
    }

    /**
     * @param Ctx $ctx
     * @return list<mixed>
     */
    private function refPart(JsonObject $node, array $ctx): array
    {
        $ref = $node->get('$ref');
        return is_string($ref) ? [$this->ref($ref, self::at($ctx, '$ref'))] : [];
    }

    /**
     * @param Ctx $ctx
     * @return list<mixed>
     */
    private function dynamicPart(JsonObject $node, array $ctx): array
    {
        foreach (['$recursiveRef', '$dynamicRef'] as $kw) {
            if (!$node->has($kw)) {
                continue;
            }
            if ($this->dynamicAmbiguous()) {
                $this->noteInexact($ctx, "{$kw} is read as a plain \$ref: which schema it names depends on the evaluation path");
            }
            return [$this->ref($node->get($kw), self::at($ctx, $kw))];
        }
        return [];
    }

    private function dynamicAmbiguous(): bool
    {
        $keys = array_keys($this->nodes);
        usort($keys, static function (string $a, string $b): int {
            [$ba, $fa] = self::unkey($a);
            [$bb, $fb] = self::unkey($b);
            return strcmp($ba, $bb) ?: strcmp($fa, $fb);
        });
        $seen = [];
        $count = 0;
        foreach ($keys as $key) {
            $entry = $this->nodes[$key];
            if (isset($seen[$entry['own']])) {
                continue;
            }
            $seen[$entry['own']] = true;
            $node = $entry['node'];
            if ($node instanceof JsonObject && ($node->has('$dynamicAnchor') || $node->get('$recursiveAnchor') === true)) {
                $count++;
            }
        }
        return $count > 1;
    }

    // ---- kinds -------------------------------------------------------------------------

    /**
     * @param Ctx $ctx
     * @return list<mixed>
     */
    private function kindPart(JsonObject $node, array $ctx): array
    {
        $types = self::typesOf($node);
        if ($types !== null) {
            return [self::anyOf(array_map(fn (mixed $kind): mixed => $this->kindType($kind, $node, $ctx, true), $types))];
        }
        // No type: each kind's keywords apply to values of that kind only.
        $parts = [];
        foreach (['string', 'number', 'array', 'object'] as $kind) {
            if (self::constrains($node, $kind)) {
                $parts[] = self::tup([self::kw('if'), self::plain($kind), $this->kindType($kind, $node, $ctx, false)]);
            }
        }
        return $parts;
    }

    /** @return list<mixed>|null */
    private static function typesOf(JsonObject $node): ?array
    {
        if ($node->has('bsonType')) {
            $bson = $node->get('bsonType');
            $types = $node->get('type');
            return [
                ...array_map(static fn (mixed $b): array => ['bson', $b], is_array($bson) ? array_values($bson) : [$bson]),
                ...($types === null ? [] : (is_array($types) ? array_values($types) : [$types])),
            ];
        }
        $type = $node->get('type');
        return match (true) {
            is_string($type) => [$type],
            is_array($type) => array_values($type),
            default => null,
        };
    }

    private static function constrains(JsonObject $node, string $kind): bool
    {
        $keys = match ($kind) {
            'string' => self::STRING_KEYS,
            'number' => self::NUMBER_KEYS,
            'array' => self::ARRAY_KEYS,
            default => self::OBJECT_KEYS,
        };
        foreach ($keys as $key) {
            if ($node->has($key)) {
                return true;
            }
        }
        return false;
    }

    /** The DXN type of a kind, with no constraint. */
    private static function plain(string $kind): DXNKeyword
    {
        return self::kw(match ($kind) {
            'array' => 'list',
            'object' => 'map',
            'null' => 'nil',
            default => $kind,
        });
    }

    /** @param Ctx $ctx */
    private function kindType(mixed $kind, JsonObject $node, array $ctx, bool $typed): mixed
    {
        if (is_array($kind)) {
            return $this->bsonType($kind[1], $node, $ctx, $typed);
        }
        return match ($kind) {
            'string' => $this->stringType($node, $ctx, $typed),
            'number', 'integer' => $this->numberType($kind, $node),
            'array' => $this->arrayType($node, $ctx),
            'object' => $this->objectType($node, $ctx),
            'boolean', 'null' => self::plain($kind),
            default => throw new JsonSchemaError(
                "{$ctx['path']}: unknown type " . self::inspect($kind) . '; ' . implode(', ', [...self::KINDS, 'integer']),
            ),
        };
    }

    // ---- MongoDB bsonType ---------------------------------------------------------------

    /** @param Ctx $ctx */
    private function bsonType(mixed $bson, JsonObject $node, array $ctx, bool $typed): mixed
    {
        return match ($bson) {
            'double' => self::refine(self::kw('float'), self::numberConstraints($node, $this->draft04())),
            'int' => self::refine(self::kw('int32'), self::numberConstraints($node, $this->draft04())),
            'long' => self::refine(self::kw('int64'), self::numberConstraints($node, $this->draft04())),
            'decimal' => self::refine(self::refine(self::kw('decimal'), ['max-precision' => 34]), self::numberConstraints($node, $this->draft04())),
            'number' => self::refine(
                self::anyOf([self::kw('int32'), self::kw('int64'), self::kw('float'), self::kw('decimal')]),
                self::numberConstraints($node, $this->draft04()),
            ),
            'string' => $this->stringType($node, $ctx, $typed),
            'bool' => self::kw('boolean'),
            'null' => self::kw('nil'),
            'array' => $this->arrayType($node, $ctx),
            'object' => $this->objectType($node, $ctx),
            'objectId' => self::kw('objectid'),
            'date' => self::refine(self::kw('timestamp'), ['resolution' => self::kw('millisecond')]),
            'binData' => self::kw('bytes'),
            'regex' => self::kw('regex'),
            'timestamp', 'javascript', 'minKey', 'maxKey', 'dbPointer', 'symbol', 'undefined', 'javascriptWithScope' => $this->noDxnValue($bson, $ctx),
            default => throw new JsonSchemaError("{$ctx['path']}: unknown bsonType " . self::inspect($bson)),
        };
    }

    /** @param Ctx $ctx */
    private function noDxnValue(string $bson, array $ctx): DXNKeyword
    {
        $this->noteInexact($ctx, "bsonType {$bson} has no DXN value; read as :any");
        return self::kw('any');
    }

    private function draft04(): bool
    {
        return $this->draft === '04' || $this->mongo;
    }

    // ---- strings -----------------------------------------------------------------------

    /** @param Ctx $ctx */
    private function stringType(JsonObject $node, array $ctx, bool $typed): mixed
    {
        $format = $node->get('format');
        $content = $node->get('contentEncoding');
        if ($this->assertFormats && $typed && is_string($format) && isset(self::LOGICAL[$format]) && self::onlyFormat($node)) {
            return self::kw(self::LOGICAL[$format]);
        }
        if ($this->assertFormats && $typed && $content === 'base64' && !$node->has('contentMediaType') && self::onlyFormat($node)) {
            return self::kw('bytes');
        }
        return self::refine(self::kw('string'), $this->stringConstraints($node, $ctx));
    }

    private static function onlyFormat(JsonObject $node): bool
    {
        return !$node->has('minLength') && !$node->has('maxLength') && !$node->has('pattern');
    }

    /**
     * @param Ctx $ctx
     * @return array<string, mixed>
     */
    private function stringConstraints(JsonObject $node, array $ctx): array
    {
        $constraints = [];
        foreach (self::STRING_KEYS as $key) {
            if (!$node->has($key) || $node->get($key) === null) {
                continue;
            }
            $value = $node->get($key);
            switch ($key) {
                case 'minLength':
                    $constraints['min-length'] = self::count($value);
                    break;
                case 'maxLength':
                    $constraints['max-length'] = self::count($value);
                    break;
                case 'pattern':
                    $regex = is_string($value) ? self::regex($value) : 'not a string';
                    if ($regex instanceof DXNRegex) {
                        $constraints['pattern'] = $regex;
                    } else {
                        $this->noteInexact(self::at($ctx, 'pattern'), 'pattern ' . self::inspect($value) . ": {$regex}; left out");
                    }
                    break;
                case 'format':
                    if ($this->assertFormats) {
                        $name = self::FORMATS[$value] ?? $value;
                        if (is_string($name) && in_array($name, Format::names(), true)) {
                            $constraints['format'] = self::kw($name);
                        }
                    }
                    break;
                case 'contentEncoding':
                    if ($this->assertFormats && in_array($value, ['base64', 'base64url', 'base32', 'base16'], true)) {
                        $constraints['encoding'] = self::kw($value);
                    }
                    break;
                case 'contentMediaType':
                    if ($this->assertFormats) {
                        $constraints['media-type'] = $value;
                    }
                    break;
            }
        }
        return $constraints;
    }

    // ---- numbers -------------------------------------------------------------------------

    private function numberType(string $kind, JsonObject $node): mixed
    {
        return self::refine(self::plain($kind), self::numberConstraints($node, $this->draft04()));
    }

    /** @return array<string, mixed> */
    private static function numberConstraints(JsonObject $node, bool $draft04): array
    {
        $constraints = [];
        foreach (self::NUMBER_KEYS as $key) {
            $n = $node->get($key);
            if ($n === null) {
                continue;
            }
            switch ($key) {
                case 'minimum':
                    $constraints[$draft04 && $node->get('exclusiveMinimum') === true ? 'exclusive-min' : 'min'] = $n;
                    break;
                case 'maximum':
                    $constraints[$draft04 && $node->get('exclusiveMaximum') === true ? 'exclusive-max' : 'max'] = $n;
                    break;
                case 'exclusiveMinimum':
                    if (self::isNumber($n)) {
                        $constraints['exclusive-min'] = $n;
                    }
                    break;
                case 'exclusiveMaximum':
                    if (self::isNumber($n)) {
                        $constraints['exclusive-max'] = $n;
                    }
                    break;
                case 'multipleOf':
                    $constraints['multiple-of'] = $n;
                    break;
            }
        }
        return $constraints;
    }

    private static function isNumber(mixed $n): bool
    {
        return is_int($n) || is_float($n) || $n instanceof DXNInteger;
    }

    // ---- arrays ----------------------------------------------------------------------------

    /** @param Ctx $ctx */
    private function arrayType(JsonObject $node, array $ctx): mixed
    {
        [$prefix, $rest] = $this->itemsOf($node, $ctx);
        $list = self::listForm($prefix, $rest);
        $constraints = array_filter([
            'min-count' => self::count($node->get('minItems')),
            'max-count' => self::count($node->get('maxItems')),
            'unique' => $node->get('uniqueItems') === true ? true : null,
        ], static fn (mixed $v): bool => $v !== null);
        if ($node->has('contains')) {
            $constraints['contains'] = $this->sub($node->get('contains'), self::at($ctx, 'contains'));
            $constraints['min-contains'] = self::count($node->has('minContains') ? $node->get('minContains') : 1);
            if ($node->has('maxContains')) {
                $constraints['max-contains'] = self::count($node->get('maxContains'));
            }
        }
        return self::refine($list, $constraints);
    }

    /**
     * The items a list must start with, and what every further one must be:
     * prefixItems and items in 2020-12, items as an array and
     * additionalItems before.
     *
     * @param Ctx $ctx
     * @return array{0: ?list<mixed>, 1: array{0: 'all'|'rest', 1: mixed}}
     */
    private function itemsOf(JsonObject $node, array $ctx): array
    {
        [$prefixKey, $restKey] = $this->draft === '2020-12' ? ['prefixItems', 'items'] : ['items', 'additionalItems'];
        $prefix = null;
        $items = $node->get($prefixKey);
        if (is_array($items)) {
            $prefix = [];
            foreach ($items as $i => $schema) {
                $prefix[] = $this->sub($schema, self::at(self::at($ctx, $prefixKey), (string) $i));
            }
        }
        if ($this->draft !== '2020-12' && self::isSchema($node->get('items'))) {
            $rest = ['all', $this->sub($node->get('items'), self::at($ctx, 'items'))];
        } elseif ($prefix === null && $this->draft === '2020-12' && $node->has($restKey)) {
            // 2020-12's items with no prefixItems is every item; an
            // additionalItems with no items array means nothing.
            $rest = ['all', $this->sub($node->get($restKey), self::at($ctx, $restKey))];
        } elseif ($prefix === null) {
            $rest = ['all', self::kw('any')];
        } elseif ($node->has($restKey)) {
            $rest = ['rest', $this->sub($node->get($restKey), self::at($ctx, $restKey))];
        } else {
            $rest = ['rest', self::kw('any')];
        }
        return [$prefix, $rest];
    }

    private static function isSchema(mixed $v): bool
    {
        return $v instanceof JsonObject || is_bool($v);
    }

    /** A count written `2.0` is the integer 2, as JSON Schema reads it. */
    private static function count(mixed $n): mixed
    {
        return is_float($n) && is_finite($n) && $n === floor($n) && abs($n) < 2 ** 62 ? (int) $n : $n;
    }

    /**
     * @param ?list<mixed> $prefix
     * @param array{0: 'all'|'rest', 1: mixed} $rest
     */
    private static function listForm(?array $prefix, array $rest): mixed
    {
        if ($prefix === null) {
            return self::listOf($rest[1]);
        }
        // Each prefix item is optional, but only after the ones before it.
        $tail = self::isKw($rest[1], 'never') ? null : self::tup([self::kw('repeat'), $rest[1], 0, null]);
        $nested = $tail;
        foreach (array_reverse($prefix) as $item) {
            $group = $nested !== null ? self::tup([self::kw('group'), $item, $nested]) : $item;
            $nested = self::tup([self::kw('repeat'), $group, 0, 1]);
        }
        return self::tup([self::kw('list-items'), $nested]);
    }

    private static function listOf(mixed $type): mixed
    {
        return self::isKw($type, 'any') ? self::kw('list') : self::tup([self::kw('list-of'), $type]);
    }

    // ---- objects ---------------------------------------------------------------------------

    /** @param Ctx $ctx */
    private function objectType(JsonObject $node, array $ctx): mixed
    {
        $propertiesNode = $node->get('properties');
        $properties = $propertiesNode instanceof JsonObject ? $propertiesNode : new JsonObject();
        $required = is_array($node->get('required')) ? $node->get('required') : [];
        $patternsNode = $node->get('patternProperties');
        $patterns = $patternsNode instanceof JsonObject ? $patternsNode->pairs() : [];

        $patternEntries = [];
        foreach ($patterns as [$pattern, $schema]) {
            $pctx = self::at(self::at($ctx, 'patternProperties'), $pattern);
            $value = $this->sub($schema, $pctx);
            $regex = self::regex($pattern);
            if (!$regex instanceof DXNRegex) {
                throw new JsonSchemaError("{$pctx['path']}: pattern " . self::inspect($pattern) . ": {$regex}");
            }
            $patternEntries[] = [$pattern, $regex, $value];
        }

        $declared = $properties->keys();
        $names = [...$declared, ...array_values(array_filter(
            $required,
            static fn (mixed $name): bool => is_string($name) && !in_array($name, $declared, true),
        ))];

        $fields = [];
        $entries = [];
        foreach ($names as $name) {
            // A `false` schema is a schema: found by its key, not its truth.
            $type = $properties->has($name)
                ? $this->sub($properties->get($name), self::at(self::at($ctx, 'properties'), $name))
                : self::kw('any');
            $schema = $properties->has($name) ? $properties->get($name) : null;
            // A pattern that matches a declared name applies to it as well.
            $matching = [];
            foreach ($patternEntries as [, $regex, $value]) {
                if (self::matches($regex, $name)) {
                    $matching[] = $value;
                }
            }
            $type = self::allOf([$type, ...$matching]);
            $hasDefault = $schema instanceof JsonObject && $schema->has('default');
            if (self::fieldName($name)) {
                $key = in_array($name, $required, true) ? $name : $name . '?';
                $fields[] = [$key, $hasDefault ? self::field($type, self::jsonValue($schema->get('default'))) : $type];
            } else {
                $entries[] = [self::tup([self::kw('const'), $name]), $type];
            }
        }

        $literalNames = array_map(static fn (array $e): mixed => $e[0]->items[1], $entries);
        $patternRegexes = array_map(static fn (array $p): DXNRegex => $p[1], $patternEntries);
        foreach ($patternEntries as [, $regex, $value]) {
            $entries[] = [self::refine(self::kw('string'), ['pattern' => $regex]), $value];
        }

        [$entries, $closed] = $this->additional($node, $ctx, $entries, $literalNames, $patternRegexes);
        $entries = $this->propertyNames($node, $ctx, $entries, $fields, $literalNames);

        $counts = array_filter([
            'min-count' => self::count($node->get('minProperties')),
            'max-count' => self::count($node->get('maxProperties')),
        ], static fn (mixed $v): bool => $v !== null);
        $shape = $fields === [] && $entries === [] && !$closed ? self::kw('map') : self::shape($fields, $entries, $closed);

        // A required name that can't be a field is required by refusing
        // the map without it.
        $present = [];
        foreach ($required as $name) {
            if (is_string($name) && !self::fieldName($name)) {
                $present[] = self::presentKey($name);
            }
        }
        return self::allOf([self::refine($shape, $counts), ...$present]);
    }

    /**
     * additionalProperties: nothing beyond the declared names and patterns
     * (`- *`), or a type for every other key.
     *
     * @param Ctx $ctx
     * @param list<array{0: mixed, 1: mixed}> $entries
     * @param list<mixed> $literalNames
     * @param list<DXNRegex> $patterns
     * @return array{0: list<array{0: mixed, 1: mixed}>, 1: bool}
     */
    private function additional(JsonObject $node, array $ctx, array $entries, array $literalNames, array $patterns): array
    {
        if (!$node->has('additionalProperties')) {
            return [$entries, false];
        }
        $schema = $node->get('additionalProperties');
        if ($schema === false) {
            return [$entries, true];
        }
        if ($schema === true) {
            return [$entries, false];
        }
        $value = $this->sub($schema, self::at($ctx, 'additionalProperties'));
        return [[...$entries, [self::otherKeys($literalNames, $patterns), $value]], false];
    }

    /**
     * The keys no literal name and no pattern takes.
     *
     * @param list<mixed> $names
     * @param list<DXNRegex> $patterns
     */
    private static function otherKeys(array $names, array $patterns): mixed
    {
        if ($names === [] && $patterns === []) {
            return self::kw('string');
        }
        $nots = [
            ...($names === [] ? [] : [self::tup([self::kw('not'), self::tup([self::kw('enum'), ...$names])])]),
            ...array_map(static fn (DXNRegex $p): DXNTuple => self::tup([self::kw('not'), self::refine(self::kw('string'), ['pattern' => $p])]), $patterns),
        ];
        return self::tup([self::kw('all-of'), self::kw('string'), ...$nots]);
    }

    /**
     * propertyNames: every key matches it. Extras that don't are refused by
     * an entry of :never; a declared name is not checked, and said so.
     *
     * @param Ctx $ctx
     * @param list<array{0: mixed, 1: mixed}> $entries
     * @param list<array{0: string, 1: mixed}> $fields
     * @param list<mixed> $literalNames
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function propertyNames(JsonObject $node, array $ctx, array $entries, array $fields, array $literalNames): array
    {
        if (!$node->has('propertyNames')) {
            return $entries;
        }
        $pctx = self::at($ctx, 'propertyNames');
        $type = $this->sub($node->get('propertyNames'), $pctx);
        if ($fields !== [] || $literalNames !== []) {
            $this->noteInexact($pctx, 'propertyNames is checked on extra keys only; declared names are not checked against it');
        }
        return [[self::tup([self::kw('not'), $type]), self::kw('never')], ...$entries];
    }

    private static function fieldName(string $name): bool
    {
        return preg_match('/^[\p{L}_][\p{L}\p{N}_-]*$/u', $name) === 1
            && !str_ends_with($name, '-')
            && !in_array($name, ['nil', 'true', 'false'], true);
    }

    private static function field(mixed $type, mixed $default): DXNStruct
    {
        return DXNStruct::keyed('field', [['type', $type], ['default', $default]]);
    }

    /**
     * @param list<array{0: string, 1: mixed}> $fields
     * @param list<array{0: mixed, 1: mixed}> $entries
     */
    private static function shape(array $fields, array $entries, bool $closed): DXNStruct
    {
        $shape = DXNStruct::keyed('shape', $fields);
        return $entries === [] && !$closed ? $shape : $shape->withBases([], $closed ? ['*'] : [], $entries);
    }

    // ---- enum, const ------------------------------------------------------------------------

    /** @return list<mixed> */
    private static function enumPart(JsonObject $node): array
    {
        $parts = [];
        $values = $node->get('enum');
        if (is_array($values)) {
            $parts[] = self::tup([self::kw('enum'), ...array_map(self::jsonValue(...), array_values($values))]);
        }
        if ($node->has('const')) {
            $parts[] = self::tup([self::kw('const'), self::jsonValue($node->get('const'))]);
        }
        return $parts;
    }

    // ---- allOf, anyOf, oneOf, not, if ---------------------------------------------------------

    /**
     * @param Ctx $ctx
     * @return list<mixed>
     */
    private function combinatorParts(JsonObject $node, array $ctx): array
    {
        $parts = [];
        foreach (['allOf' => 'all-of', 'anyOf' => 'any-of', 'oneOf' => 'one-of'] as $key => $head) {
            $list = $node->get($key);
            if (is_array($list)) {
                $types = [];
                foreach ($list as $i => $s) {
                    $types[] = $this->sub($s, self::at(self::at($ctx, $key), (string) $i));
                }
                $parts[] = self::tup([self::kw($head), ...$types]);
            }
        }
        if ($node->has('not')) {
            $parts[] = self::tup([self::kw('not'), $this->sub($node->get('not'), self::at($ctx, 'not'))]);
        }
        return $parts;
    }

    /**
     * @param Ctx $ctx
     * @return list<mixed>
     */
    private function conditionalPart(JsonObject $node, array $ctx): array
    {
        if (!$node->has('if') || (!$node->has('then') && !$node->has('else'))) {
            return [];
        }
        $test = $this->sub($node->get('if'), self::at($ctx, 'if'));
        $then = $node->has('then') ? $this->sub($node->get('then'), self::at($ctx, 'then')) : self::kw('any');
        $otherwise = $node->has('else') ? $this->sub($node->get('else'), self::at($ctx, 'else')) : self::kw('any');
        return [self::tup([self::kw('if'), $test, $then, $otherwise])];
    }

    /**
     * dependentRequired, dependentSchemas, and draft-04's to 07's
     * dependencies: when a property is present, others are, or the value
     * matches a schema.
     *
     * @param Ctx $ctx
     * @return list<mixed>
     */
    private function dependentParts(JsonObject $node, array $ctx): array
    {
        $required = self::pairsOf($node->get('dependentRequired'));
        $schemas = self::pairsOf($node->get('dependentSchemas'));
        $oldRequired = [];
        $oldSchemas = [];
        foreach (self::pairsOf($node->get('dependencies')) as $pair) {
            if (is_array($pair[1])) {
                $oldRequired[] = $pair;
            } else {
                $oldSchemas[] = $pair;
            }
        }
        $parts = [];
        foreach ([...$required, ...$oldRequired] as [$name, $needed]) {
            $parts[] = self::tup([self::kw('if'), self::presentShape([$name]), self::presentShape(is_array($needed) ? array_values($needed) : [])]);
        }
        // Each schema converted where it stands, so a reference to it is
        // the same schema.
        $keyed = [
            ...array_map(static fn (array $pair): array => ['dependentSchemas', ...$pair], $schemas),
            ...array_map(static fn (array $pair): array => ['dependencies', ...$pair], $oldSchemas),
        ];
        foreach ($keyed as [$key, $name, $schema]) {
            $type = $this->sub($schema, self::at(self::at($ctx, $key), $name));
            $parts[] = self::tup([self::kw('if'), self::presentShape([$name]), $type]);
        }
        return $parts;
    }

    /** @return list<array{0: string, 1: mixed}> */
    private static function pairsOf(mixed $object): array
    {
        return $object instanceof JsonObject ? $object->pairs() : [];
    }

    /**
     * A map with these keys present: a shape whose fields are those names,
     * each of any value.
     *
     * @param list<mixed> $names
     */
    private static function presentShape(array $names): mixed
    {
        $names = array_values(array_filter($names, 'is_string'));
        $fields = array_values(array_filter($names, self::fieldName(...)));
        $others = array_values(array_filter($names, static fn (string $n): bool => !self::fieldName($n)));
        $parts = [
            ...($fields === [] ? [] : [DXNStruct::keyed('shape', array_map(static fn (string $f): array => [$f, self::kw('any')], $fields))]),
            ...array_map(self::presentKey(...), $others),
        ];
        return $parts === [] ? self::kw('any') : self::allOf($parts);
    }

    /**
     * A map with `$name` present, whatever `$name` is: one that a shape
     * refusing that key does not take.
     */
    private static function presentKey(string $name): mixed
    {
        $absent = self::shape([], [[self::tup([self::kw('const'), $name]), self::kw('never')]], false);
        return self::allOf([self::kw('map'), self::tup([self::kw('not'), $absent])]);
    }

    // ---- unevaluated ---------------------------------------------------------------------------

    private const BRANCHING = ['if', 'then', 'else', 'anyOf', 'oneOf', 'dependentSchemas'];

    /**
     * unevaluatedProperties where it can be told from the schema alone: the
     * properties and patterns of this schema, its allOf branches and what
     * it references. Each part that evaluates properties only when
     * something passes -- if/then/else, an anyOf or oneOf branch, a
     * dependentSchemas entry -- is one condition: the rule is written for
     * each way they can go, chosen by the same conditions with {:if}.
     *
     * @param Ctx $ctx
     * @return list<mixed>
     */
    private function unevaluatedPropsPart(JsonObject $node, array $ctx): array
    {
        if (!$node->has('unevaluatedProperties')) {
            return [];
        }
        $schema = $node->get('unevaluatedProperties');
        $uctx = self::at($ctx, 'unevaluatedProperties');
        $base = $node->without('unevaluatedProperties', ...self::BRANCHING);
        $conditions = $this->conditions($node, $ctx);
        if (count($conditions) > 6) {
            $this->noteInexact($uctx, 'unevaluatedProperties over more than six conditional branches; left out');
            return [];
        }
        $rule = $this->byConditions($conditions, [$base], $schema, $ctx, $uctx, false);
        return $rule === null ? [] : [$rule];
    }

    /**
     * `[test, whenPassed, whenFailed]` each: the type to test, and the
     * schemas whose properties or items count as evaluated either way.
     *
     * @param Ctx $ctx
     * @return list<array{0: mixed, 1: list<mixed>, 2: list<mixed>}>
     */
    private function conditions(JsonObject $node, array $ctx): array
    {
        $conditions = [];
        if ($node->has('if')) {
            $type = $this->sub($node->get('if'), self::at($ctx, 'if'));
            $conditions[] = [
                $type,
                [$node->get('if'), $node->has('then') ? $node->get('then') : true],
                [$node->has('else') ? $node->get('else') : true],
            ];
        }
        foreach (['anyOf', 'oneOf'] as $key) {
            $list = $node->get($key);
            foreach (is_array($list) ? $list : ($list === null ? [] : [$list]) as $i => $branch) {
                $type = $this->sub($branch, self::at(self::at($ctx, $key), (string) $i));
                $conditions[] = [$type, [$branch], []];
            }
        }
        foreach (self::pairsOf($node->get('dependentSchemas')) as [$name, $schema]) {
            $conditions[] = [self::presentShape([$name]), [$schema], []];
        }
        return $conditions;
    }

    /**
     * The rule for each way `$conditions` can go, `null` for none.
     *
     * @param list<array{0: mixed, 1: list<mixed>, 2: list<mixed>}> $conditions
     * @param list<mixed> $evaluated
     * @param Ctx $ctx
     * @param Ctx $uctx
     */
    private function byConditions(array $conditions, array $evaluated, mixed $schema, array $ctx, array $uctx, bool $items): mixed
    {
        if ($conditions === []) {
            $all = JsonObject::of([['allOf', $evaluated]]);
            return $items
                ? $this->itemsRule($schema, $this->evaluatedItems($all, $ctx['base'], [], [$ctx['path'], 'merged']), $uctx)
                : $this->unevaluatedRule($schema, $this->evaluatedProps($all, $ctx['base'], []), $uctx);
        }
        [$test, $passed, $failed] = $conditions[0];
        $more = array_slice($conditions, 1);
        $yes = $this->byConditions($more, [...$evaluated, ...$passed], $schema, $ctx, $uctx, $items);
        $no = $this->byConditions($more, [...$evaluated, ...$failed], $schema, $ctx, $uctx, $items);
        if (serialize($yes) === serialize($no)) {
            return $yes;
        }
        return self::tup([self::kw('if'), $test, $yes ?? self::kw('any'), $no ?? self::kw('any')]);
    }

    /**
     * The type that says unevaluatedProperties for what `$evaluated` names,
     * `null` for none.
     *
     * @param Evaluated $evaluated
     * @param Ctx $uctx
     */
    private function unevaluatedRule(mixed $schema, array $evaluated, array $uctx): mixed
    {
        if ($evaluated[0] === 'all') {
            return null;
        }
        if ($evaluated[0] === 'dynamic') {
            $this->noteInexact($uctx, "unevaluatedProperties depends on {$evaluated[1]}; left out");
            return null;
        }
        $regexes = [];
        foreach ($evaluated[2] as $p) {
            $regex = self::regex($p);
            if (!$regex instanceof DXNRegex) {
                throw new JsonSchemaError("{$uctx['path']}: pattern " . self::inspect($p) . ": {$regex}");
            }
            $regexes[] = $regex;
        }
        $names = array_keys($evaluated[1]);
        sort($names, SORT_STRING);
        $names = array_map('strval', $names);
        $entries = [
            ...array_map(static fn (string $n): array => [self::tup([self::kw('const'), $n]), self::kw('any')], $names),
            ...array_map(static fn (DXNRegex $r): array => [self::refine(self::kw('string'), ['pattern' => $r]), self::kw('any')], $regexes),
        ];
        if ($schema === false) {
            [$rest, $closed] = [[], true];
        } elseif ($schema === true) {
            [$rest, $closed] = [[], false];
        } else {
            [$rest, $closed] = [[[self::otherKeys($names, $regexes), $this->sub($schema, $uctx)]], false];
        }
        if ($rest === [] && !$closed) {
            return null;
        }
        return self::tup([self::kw('if'), self::kw('map'), self::shape([], [...$entries, ...$rest], $closed)]);
    }

    /**
     * What a schema evaluates of an object's properties: `['all']`, or the
     * names and patterns, or `['dynamic', why]`.
     *
     * @param list<string> $seen the references followed already (and 'branch' below an allOf)
     * @return Evaluated
     */
    private function evaluatedProps(mixed $node, string $base, array $seen): array
    {
        if (!$node instanceof JsonObject) {
            return ['static', [], []];
        }
        $base = $this->resourceId($node, $base);
        if ($node->has('additionalProperties') || ($node->has('unevaluatedProperties') && $seen !== [])) {
            return ['all'];
        }
        foreach (['anyOf', 'oneOf', 'if', 'then', 'else', 'dependentSchemas'] as $key) {
            if (self::namesProps($node->get($key))) {
                return ['dynamic', 'which anyOf, oneOf, if or dependentSchemas branch passed'];
            }
        }
        $names = [];
        foreach (self::pairsOf($node->get('properties')) as [$name]) {
            $names[$name] = true;
        }
        $own = ['static', $names, array_map(static fn (array $p): string => $p[0], self::pairsOf($node->get('patternProperties')))];
        $children = [];
        if (is_string($node->get('$ref'))) {
            [$key, $target] = $this->target($base, $node->get('$ref'));
            if (!in_array($key, $seen, true)) {
                $children[] = [$target, self::unkey($key)[0], [...$seen, $key]];
            }
        }
        $allOf = $node->get('allOf');
        foreach (is_array($allOf) ? $allOf : ($allOf === null ? [] : [$allOf]) as $branch) {
            $children[] = [$branch, $base, [...$seen, 'branch']];
        }
        $acc = $own;
        foreach ($children as [$child, $b, $s]) {
            $acc = self::mergeEval($acc, $this->evaluatedProps($child, $b, $s));
        }
        return $acc;
    }

    private static function namesProps(mixed $node): bool
    {
        if (is_array($node)) {
            foreach ($node as $n) {
                if (self::namesProps($n)) {
                    return true;
                }
            }
            return false;
        }
        if (!$node instanceof JsonObject) {
            return false;
        }
        foreach (['properties', 'patternProperties', 'additionalProperties', 'unevaluatedProperties'] as $key) {
            if ($node->has($key)) {
                return true;
            }
        }
        foreach ($node->pairs() as [, $v]) {
            if (self::namesProps($v)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param Evaluated $a
     * @param Evaluated $b
     * @return Evaluated
     */
    private static function mergeEval(array $a, array $b): array
    {
        if ($a[0] === 'all' || $b[0] === 'all') {
            return ['all'];
        }
        if ($a[0] === 'dynamic') {
            return $a;
        }
        if ($b[0] === 'dynamic') {
            return $b;
        }
        return ['static', $a[1] + $b[1], [...$a[2], ...$b[2]]];
    }

    /**
     * unevaluatedItems, as unevaluatedProperties: the items beyond the
     * longest prefix this schema, its allOf branches and what it
     * references give, unless an `items` takes them all; an item
     * `contains` matches is evaluated too. Conditional branches are written
     * out with {:if}.
     *
     * @param Ctx $ctx
     * @return list<mixed>
     */
    private function unevaluatedItemsPart(JsonObject $node, array $ctx): array
    {
        if (!$node->has('unevaluatedItems')) {
            return [];
        }
        $schema = $node->get('unevaluatedItems');
        $uctx = self::at($ctx, 'unevaluatedItems');
        $base = $node->without('unevaluatedItems', ...self::BRANCHING);
        $conditions = $this->conditions($node, $ctx);
        if (count($conditions) > 6) {
            $this->noteInexact($uctx, 'unevaluatedItems over more than six conditional branches; left out');
            return [];
        }
        $rule = $this->byConditions($conditions, [$base], $schema, $ctx, $uctx, true);
        return $rule === null ? [] : [$rule];
    }

    /**
     * @param ItemsEvaluated $evaluated
     * @param Ctx $uctx
     */
    private function itemsRule(mixed $schema, array $evaluated, array $uctx): mixed
    {
        if ($evaluated[0] === 'all') {
            return null;
        }
        if ($evaluated[0] === 'dynamic') {
            $this->noteInexact($uctx, "unevaluatedItems depends on {$evaluated[1]}; left out");
            return null;
        }
        $value = $this->sub($schema, $uctx);
        $contained = array_map(fn (array $c): mixed => $this->sub($c[0], $c[1]), $evaluated[2]);
        $rest = $contained === [] ? $value : self::anyOf([...$contained, $value]);
        if (self::isKw($rest, 'any')) {
            return null;
        }
        $list = self::listForm($evaluated[1] === 0 ? null : array_fill(0, $evaluated[1], self::kw('any')), ['rest', $rest]);
        return self::tup([self::kw('if'), self::kw('list'), $list]);
    }

    /**
     * What a schema evaluates of a list: `['all']`, or the prefix length
     * and the contains schemas, or `['dynamic', why]`. `$where`, `[path,
     * how]`, is where the schema is, for a report from inside a contains:
     * `'merged'` for the parts of one schema gathered under the path they
     * stand at.
     *
     * @param list<string> $seen
     * @param array{0: string, 1: 'merged'|'own'} $where
     * @return ItemsEvaluated
     */
    private function evaluatedItems(mixed $node, string $base, array $seen, array $where): array
    {
        [$path, $how] = $where;
        if (!$node instanceof JsonObject) {
            return ['static', 0, []];
        }
        $base = $this->resourceId($node, $base);
        [$prefixKey, $restKey] = $this->draft === '2020-12' ? ['prefixItems', 'items'] : ['items', 'additionalItems'];
        if (
            ($this->draft !== '2020-12' && self::isSchema($node->get('items')))
            || ($node->has($restKey) && ($this->draft === '2020-12' || is_array($node->get('items'))))
        ) {
            return ['all'];
        }
        if ($node->has('unevaluatedItems') && $seen !== []) {
            return ['all'];
        }
        foreach (['anyOf', 'oneOf', 'if', 'then', 'else', 'dependentSchemas'] as $key) {
            if (self::itemsNamed($node->get($key))) {
                return ['dynamic', 'which anyOf, oneOf or if branch passed, below the top'];
            }
        }
        $ownPrefix = is_array($node->get($prefixKey)) ? count($node->get($prefixKey)) : 0;
        $contains = $node->has('contains') && in_array($this->draft, ['2020-12', '2019-09'], true)
            ? [[$node->get('contains'), ['base' => $base, 'pointer' => "/\0" . $path . '/contains', 'top' => null, 'path' => $path . '/contains']]]
            : [];
        $children = [];
        if (is_string($node->get('$ref'))) {
            [$key, $target] = $this->target($base, $node->get('$ref'));
            if (!in_array($key, $seen, true)) {
                $children[] = [$target, self::unkey($key)[0], [...$seen, $key], [self::unkey($key)[1], 'own']];
            }
        }
        $allOf = $node->get('allOf');
        foreach (is_array($allOf) ? $allOf : ($allOf === null ? [] : [$allOf]) as $i => $branch) {
            $children[] = [$branch, $base, [...$seen, 'branch'], [$how === 'merged' ? $path : "{$path}/allOf/{$i}", 'own']];
        }
        $acc = ['static', $ownPrefix, $contains];
        foreach ($children as [$child, $b, $s, $at]) {
            $acc = self::mergeItems($acc, $this->evaluatedItems($child, $b, $s, $at));
        }
        return $acc;
    }

    private static function itemsNamed(mixed $node): bool
    {
        if (is_array($node)) {
            foreach ($node as $n) {
                if (self::itemsNamed($n)) {
                    return true;
                }
            }
            return false;
        }
        if (!$node instanceof JsonObject) {
            return false;
        }
        foreach (['items', 'prefixItems', 'additionalItems', 'contains', 'unevaluatedItems'] as $key) {
            if ($node->has($key)) {
                return true;
            }
        }
        foreach ($node->pairs() as [, $v]) {
            if (self::itemsNamed($v)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param ItemsEvaluated $a
     * @param ItemsEvaluated $b
     * @return ItemsEvaluated
     */
    private static function mergeItems(array $a, array $b): array
    {
        if ($a[0] === 'all' || $b[0] === 'all') {
            return ['all'];
        }
        if ($a[0] === 'dynamic') {
            return $a;
        }
        if ($b[0] === 'dynamic') {
            return $b;
        }
        return ['static', max($a[1], $b[1]), [...$a[2], ...$b[2]]];
    }

    // ---- annotations ---------------------------------------------------------------------------

    /** @param Ctx $ctx */
    private function annotated(mixed $type, JsonObject $node, array $ctx): mixed
    {
        $notes = [];
        foreach (self::NOTES as $key => $name) {
            if ($node->has($key)) {
                $value = $node->get($key);
                $notes[$name] = $key === 'examples'
                    ? new DXNList(array_map(self::jsonValue(...), is_array($value) ? $value : ($value === null ? [] : [$value])))
                    : self::jsonValue($value);
            }
        }
        if ($node->get('deprecated') === false) {
            unset($notes['deprecated']);
        }

        $meta = [];
        foreach ($node->pairs() as [$k, $v]) {
            if (!in_array($k, self::KNOWN, true)) {
                $meta['json-schema.' . $k] = self::jsonValue($v);
            }
        }
        $format = $node->get('format');
        if (is_string($format) && (!$this->assertFormats || !in_array(self::FORMATS[$format] ?? $format, Format::names(), true))) {
            $meta['json-schema.format'] = $format;
        }
        if (!$this->assertFormats) {
            foreach (['contentEncoding', 'contentMediaType'] as $k) {
                if ($node->has($k)) {
                    $meta['json-schema.' . $k] = $node->get($k);
                }
            }
        }
        if ($node->has('$comment')) {
            $meta['json-schema.$comment'] = $node->get('$comment');
        }
        if ($node->has('contentSchema')) {
            $meta['json-schema.contentSchema'] = self::jsonValue($node->get('contentSchema'));
        }
        if ($meta !== []) {
            ksort($meta, SORT_STRING);
            $notes['meta'] = MapBuilder::build(
                array_map(static fn (string $k, mixed $v): array => [$k, $v], array_keys($meta), array_values($meta)),
                static fn (string $m): \Throwable => new JsonSchemaError($m),
            );
        }
        $discriminator = $node->get('discriminator');
        if ($discriminator instanceof JsonObject && is_string($discriminator->get('propertyName'))) {
            $notes['discriminator'] = self::kw($discriminator->get('propertyName'));
        }
        if ($notes === []) {
            return $type;
        }
        ksort($notes, SORT_STRING);
        return self::tup([self::kw('doc'), $type, $notes]);
    }

    // ---- structs (structs: true) -----------------------------------------------------------------

    /**
     * A named object schema written as a `%schema`: only where the shape
     * is the whole definition, so nothing but its fields is said.
     */
    private static function asStruct(mixed $type): mixed
    {
        if ($type instanceof DXNStruct && $type->name === 'shape') {
            return self::renamed($type);
        }
        if (
            $type instanceof DXNTuple && count($type->items) === 3 && self::isKw($type->items[0], 'doc')
            && $type->items[1] instanceof DXNStruct && $type->items[1]->name === 'shape'
        ) {
            return self::tup([self::kw('doc'), self::renamed($type->items[1]), $type->items[2]]);
        }
        return $type;
    }

    private static function renamed(DXNStruct $shape): DXNStruct
    {
        $schema = DXNStruct::keyed('schema', $shape->pairs());
        return $shape->hasBodyClauses() ? $schema->withBases($shape->bases, $shape->drops, $shape->rest) : $schema;
    }

    // ---- building DXN values ---------------------------------------------------------------------

    private static function kw(string $name): DXNKeyword
    {
        return new DXNKeyword($name);
    }

    private static function isKw(mixed $value, string $name): bool
    {
        return $value instanceof DXNKeyword && $value->name === $name;
    }

    /** @param list<mixed> $items */
    private static function tup(array $items): DXNTuple
    {
        return new DXNTuple($items);
    }

    /** @param array<string, mixed> $constraints */
    private static function refine(mixed $base, array $constraints): mixed
    {
        if ($constraints === []) {
            return $base;
        }
        ksort($constraints, SORT_STRING);
        return self::tup([self::kw('refine'), $base, $constraints]);
    }

    private static function matches(DXNRegex $regex, string $text): bool
    {
        return preg_match($regex->toPcrePattern(), $text) === 1;
    }

    private static function inspect(mixed $value): string
    {
        return json_encode($value instanceof JsonObject ? 'an object' : $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: get_debug_type($value);
    }

    /**
     * An ECMA-262 pattern as PCRE source, prefixed `/`, or why it can't be
     * written: `\d` and `\w` as ASCII, `$` as the very end, `\uXXXX` as a
     * code point.
     */
    private static function ecmaToPcre(string $pattern): string
    {
        $chars = preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return 'not valid UTF-8';
        }
        $out = '';
        $class = false;
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $next = $chars[$i + 1];
                $i++;
                switch (true) {
                    case $next === 'd':
                        $out .= $class ? '0-9' : '[0-9]';
                        break;
                    case $next === 'D' && !$class:
                        $out .= '[^0-9]';
                        break;
                    case $next === 'w':
                        $out .= $class ? 'A-Za-z0-9_' : '[A-Za-z0-9_]';
                        break;
                    case $next === 'W' && !$class:
                        $out .= '[^A-Za-z0-9_]';
                        break;
                    case $next === 'D' || $next === 'W':
                        return "\\{$next} inside a character class can't be written in PCRE here";
                    case $next === 'u':
                        $hex = implode('', array_slice($chars, $i + 1, 4));
                        if (preg_match('/^[0-9A-Fa-f]{4}$/', $hex) === 1) {
                            $out .= '\\x{' . $hex . '}';
                            $i += 4;
                        } else {
                            $out .= '\\u';
                        }
                        break;
                    default:
                        $out .= '\\' . $next;
                }
                continue;
            }
            if ($c === '[' && !$class) {
                $class = true;
            } elseif ($c === ']' && $class) {
                $class = false;
            } elseif ($c === '$' && !$class) {
                $out .= '\\z';
                continue;
            }
            $out .= $c;
        }
        return '/' . $out;
    }
}
