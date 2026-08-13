<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * DXN `regex` (`~r/pattern/flags`) -- PCRE-style semantics (matching
 * Erlang `:re`/Elixir `Regex`, per `dxn/DXN.md` §2.3), which happens
 * to make PHP's own PCRE-backed `preg_*` a *better* native fit than
 * JS's `RegExp` had: DXN's `x` (extended) and `r` (ungreedy) flags
 * both have real PHP modifier equivalents (`r` maps to PHP's
 * uppercase `U`, distinct from lowercase `u`/unicode) where JS had
 * neither. Only `f` (firstline) has no PHP preg modifier to reach
 * for, so {@see self::toPcrePattern()} is guarded on that alone, not
 * on `x`/`f`/`r` wholesale.
 */
final class DXNRegex implements DXNValueInterface
{
    private const DXN_TYPE = 'regex';
    private const FLAG_NAMES = ['i', 'm', 's', 'u', 'x', 'f', 'r'];

    public readonly string $pattern;
    /** @var array<string, bool> keys: i, m, s, u, x, f, r */
    public readonly array $flags;

    /** @param array<string, bool> $flags */
    public function __construct(string $pattern, array $flags = [])
    {
        $extra = array_diff(array_keys($flags), self::FLAG_NAMES);
        if ($extra !== []) {
            throw new \InvalidArgumentException('unknown regex flag(s): ' . implode(',', $extra));
        }
        $normalized = [];
        foreach (self::FLAG_NAMES as $name) {
            $normalized[$name] = $flags[$name] ?? false;
        }
        $this->pattern = $pattern;
        $this->flags = $normalized;
    }

    public function toPcrePattern(string $delimiter = '/'): string
    {
        if ($this->flags['f']) {
            throw new \LogicException('cannot convert to a PCRE pattern string: "f" (firstline) has no PHP preg modifier equivalent');
        }
        $modifiers = '';
        foreach (['i' => 'i', 'm' => 'm', 's' => 's', 'u' => 'u', 'x' => 'x', 'r' => 'U'] as $dxnFlag => $pcreModifier) {
            if ($this->flags[$dxnFlag]) {
                $modifiers .= $pcreModifier;
            }
        }
        $escaped = str_replace($delimiter, '\\' . $delimiter, $this->pattern);
        return $delimiter . $escaped . $delimiter . $modifiers;
    }

    public static function fromPcrePattern(string $pcrePattern): self
    {
        if (!preg_match('/^(.)(.*)\1([a-zA-Z]*)$/s', $pcrePattern, $m)) {
            throw new \InvalidArgumentException("not a valid delimited PCRE pattern: {$pcrePattern}");
        }
        [, $delimiter, $body, $modifiers] = $m;
        $pattern = str_replace('\\' . $delimiter, $delimiter, $body);
        return new self($pattern, [
            'i' => str_contains($modifiers, 'i'),
            'm' => str_contains($modifiers, 'm'),
            's' => str_contains($modifiers, 's'),
            'u' => str_contains($modifiers, 'u'),
            'x' => str_contains($modifiers, 'x'),
            'r' => str_contains($modifiers, 'U'),
        ]);
    }

    public function compareTo(mixed $other): int
    {
        return Equality::dxnCompare($this, $other);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->pattern === $other->pattern && $this->flags === $other->flags;
    }

    public function dxnType(): string
    {
        return self::DXN_TYPE;
    }
}
