<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * The result of compiling one `%schema(N){}` entry from a `.dxns`
 * document ({@see Compiler}) -- enough to both validate a decoded
 * value and convert it both directions between `.dxnb`'s
 * always-positional wire shape and a named field map.
 *
 * `fields` is ordered -- that order is the canonical position <-> name
 * mapping `.dxnb`'s positional struct encoding needs, and it is the
 * order the fields are written in the `%schema(N){...}` body.
 *
 * `version` is which version of `name` this is (DXN.md §4.8), 1 for a
 * schema written without a header.
 */
final class Compiled
{
    /** @param list<Field> $fields */
    public function __construct(
        public readonly ?string $name = null,
        public readonly array $fields = [],
        public readonly int $version = 1,
    ) {
    }

    /** A schema's `name` is only known once {@see Compiler} finishes compiling its enclosing document -- returns a copy with it set. */
    public function withName(string $name): self
    {
        return new self($name, $this->fields, $this->version);
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_map(static fn (Field $f): string => $f->name, $this->fields);
    }

    /**
     * `$instance` -- a registered application class instance -- as the
     * keyed struct named `$name` it is written as (DXN.md §4.3, §4.7), its fields in
     * this schema's order. A field it does not have -- a property the
     * class does not declare, or a typed one left uninitialised -- is
     * absent, never null: a reader refuses null unless the type admits
     * it, and a property holding null is a field holding nil. Any other
     * public property is an extra field, written back with the struct,
     * as an Elixir struct's undeclared keys are.
     */
    public function structOf(string $name, object $instance): \JOetjen\Dextrin\Value\DXNStruct
    {
        $vars = get_object_vars($instance);
        $declared = array_flip($this->fieldNames());
        $pairs = [];
        foreach ($this->fieldNames() as $field) {
            if (array_key_exists($field, $vars)) {
                $pairs[] = [$field, $vars[$field]];
            }
        }
        $extra = [];
        foreach ($vars as $key => $value) {
            if (!isset($declared[$key])) {
                $extra[] = [$key, $value];
            }
        }
        return \JOetjen\Dextrin\Value\DXNStruct::keyed($name, $pairs, null, $extra);
    }
}
