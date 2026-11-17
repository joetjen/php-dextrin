<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Value\DXNDate;
use JOetjen\Dextrin\Value\DXNDateTime;
use JOetjen\Dextrin\Value\DXNLocalDateTime;
use JOetjen\Dextrin\Value\DXNTime;

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

    /**
     * The value a missing field takes: its default, or for a computed
     * one (`%field{computed: :now}`, {@see Computed}) the value computed
     * now; {@see self::NO_DEFAULT} when it has none.
     */
    public function defaultValue(): mixed
    {
        return self::filled($this->default);
    }

    /**
     * A field's `default` as the value it fills: computed now, in UTC,
     * or as written.
     *
     * @param mixed $default a field's {@see self::$default}
     * @return mixed the value it fills
     */
    public static function filled(mixed $default): mixed
    {
        if (!$default instanceof Computed) {
            return $default;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nanos = $now->getTimestamp() * 1_000_000_000 + (int) $now->format('u') * 1_000;
        return match ($default->kind) {
            'date' => new DXNDate((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j')),
            'time' => new DXNTime((int) $now->format('G'), (int) $now->format('i'), (int) $now->format('s'), (int) $now->format('u') * 1_000),
            'datetime' => new DXNDateTime($nanos, 0),
            'local-datetime' => new DXNLocalDateTime($nanos),
        };
    }
}
