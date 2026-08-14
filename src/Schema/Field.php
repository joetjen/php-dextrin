<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * One compiled field spec inside a {@see Compiled} schema. `required`
 * comes from the `?`-suffixed key convention (a field key ending in
 * `?` is optional; no separate flag exists in `.dxns` itself);
 * `default`/`description` only ever come from the `%field{...}` escape
 * hatch, since optionality is already fully covered by the key suffix.
 */
final class Field
{
    public const NO_DEFAULT = NoDefault::Instance;

    public readonly string $name;
    public readonly bool $required;
    public readonly TypeExpr $type;
    public readonly mixed $default;
    public readonly ?string $description;

    public function __construct(
        string $name,
        bool $required = true,
        ?TypeExpr $type = null,
        mixed $default = NoDefault::Instance,
        ?string $description = null,
    ) {
        $this->name = $name;
        $this->required = $required;
        $this->type = $type ?? TypeExpr::any();
        $this->default = $default;
        $this->description = $description;
    }
}
