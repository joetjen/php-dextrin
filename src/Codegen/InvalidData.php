<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Codegen;

use JOetjen\Dextrin\DXNError;

/**
 * Data that code generated from a schema ({@see Php}) refuses: a
 * {@see DXNError} at stage `schema` that names the field it stands in.
 * `$path` is that field, outermost first -- a struct's or shape's field
 * name, `[2]` for a list's third item, a map key as DXN writes it -- and
 * `$reason` what is wrong with the value there. The message is both:
 * `customer.code: "nope" does not meet %{pattern:@regex "^[A-Z]{3}$"}`.
 *
 * `category` is DXN.md §5's: `schema/type-mismatch`,
 * `schema/missing-field`, `schema/undeclared-field`,
 * `schema/field-count`, `schema/unknown-version` or `schema/deprecated`.
 */
final class InvalidData extends DXNError
{
    /** @var list<string> the field, outermost first; `[]` for the value itself */
    public readonly array $path;

    /**
     * @param string $reason what is wrong with the value
     * @param list<string> $path the field it stands in, outermost first
     * @param string $category its category (DXN.md §5)
     */
    public function __construct(public readonly string $reason, array $path = [], string $category = 'schema/type-mismatch')
    {
        parent::__construct(self::text($path, $reason), 'schema', null, null, $category);
        $this->path = $path;
    }

    /**
     * A value that is not what was expected.
     *
     * @param string $what what was expected, in words or as DXNS names it
     * @param mixed $value what was given
     * @return self the refusal
     */
    public static function expected(string $what, mixed $value): self
    {
        return new self("expected {$what}, got " . Runtime::show($value));
    }

    /**
     * This refusal one field further out: `$segment` before its path.
     *
     * @param string $segment the field, item or key it stands in
     * @return self the refusal, with the longer path
     */
    public function under(string $segment): self
    {
        return new self($this->reason, [$segment, ...$this->path], $this->category ?? 'schema/type-mismatch');
    }

    /**
     * The path in words -- names joined by dots, an item's `[n]` straight
     * after its list -- then the reason.
     *
     * @param list<string> $path
     */
    private static function text(array $path, string $reason): string
    {
        $where = '';
        foreach ($path as $segment) {
            $where .= ($where === '' || str_starts_with($segment, '[')) ? $segment : ".{$segment}";
        }
        return $where === '' ? $reason : "{$where}: {$reason}";
    }
}
