<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

/**
 * A value with no DXN meaning -- a closure, a resource -- refused by the
 * printer and the binary encoder alike, with DXN.md §5's
 * `encode/unsupported` category.
 */
final class UnsupportedValue extends \InvalidArgumentException implements HasCategory
{
    public static function of(mixed $value): self
    {
        return new self('cannot encode value with no DXN representation: ' . get_debug_type($value));
    }

    /**
     * A symbol whose name is not an identifier (DXN.md §1.1): it has no
     * text form, since writing the name verbatim would read back as
     * something else, or as nothing.
     */
    public static function symbol(string $name): self
    {
        return new self('a symbol named ' . json_encode($name) . ' has no text form: its name is not an identifier');
    }

    /**
     * A `%schema` or `%shape` built from others (DXNS.md §4b) is text
     * only: `.dxnb` has no place for its head's clauses or its `- field`s.
     * So is one that is closed (`- *`) or types its extras (`* K => V`,
     * §4c).
     */
    public static function builtStruct(string $name): self
    {
        return new self(
            "a %{$name} built from others (extends, includes, - field), closed (- *) "
            . 'or with typed extras (* K => V) has no .dxnb form',
        );
    }

    public function category(): string
    {
        return 'encode/unsupported';
    }
}
