<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

use JOetjen\Dextrin\Binary\Decoder;
use JOetjen\Dextrin\Binary\Encoder;
use JOetjen\Dextrin\Text\Formatter;
use JOetjen\Dextrin\Text\Parser;
use JOetjen\Dextrin\Text\Printer;

/**
 * Public API entry point: `.dxn` text and `.dxnb` binary decode/
 * encode, plus `.dxns` schema-backed struct decoding and encode-time
 * validation via an optional {@see Registry}.
 *
 * This is a port of `dextrin` (Elixir, on Hex.pm), the reference
 * implementation. A sibling of `node-dextrin` (Node.js, on npm) reads
 * and writes the exact same `.dxn`/`.dxnb`/`.dxns` documents -- see
 * the README's own "Other language implementations" section.
 */
final class Dextrin
{
    private function __construct()
    {
    }

    /**
     * Decodes `.dxn` text into a value -- every DXN type maps to a
     * native PHP value or a `DXN*` wrapper class, see the project's
     * value-model mapping (`README.md`) for the full picture. Throws
     * {@see DXNError} on malformed input, including a schema violation
     * for any registered `struct` name the text uses.
     *
     * `$registry` (a {@see Registry} built via {@see Schema::compile()})
     * enables schema-backed `struct` decoding and custom-tag decoding
     * for any name it knows -- a `struct`/tag with no matching registry
     * entry falls back to an opaque `DXNStruct`/`DXNCustomTag`, never a
     * hard failure.
     *
     * `$strict` refuses the extra fields of a schema-backed struct whose
     * schema does not type them (DXNS.md §7). `$deprecations` says how
     * often data going through something deprecated warns -- `'once'`
     * per registry and deprecated thing (the default), `'every'`,
     * `'off'`, or `'error'`, which refuses it as `schema/deprecated`
     * (DXNS.md §1b) -- and `$onDeprecated` receives each warning in place
     * of `trigger_error($text, E_USER_DEPRECATED)`; each is passed on to
     * the registry ({@see Registry::putDeprecations()},
     * {@see Registry::putOnDeprecated()}).
     */
    public static function decode(
        string $text,
        ?Registry $registry = null,
        ?bool $strict = null,
        ?string $deprecations = null,
        ?callable $onDeprecated = null,
    ): mixed {
        try {
            return Parser::parse($text, self::withOptions($registry, $strict, $deprecations, $onDeprecated));
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'parse');
        }
    }

    /**
     * Encodes a value back to `.dxn` text. Single-line,
     * minimal-whitespace by default; pass `pretty: true` for
     * multi-line, indented output instead: the top-level collection
     * one entry per line, a nested one on one line when it fits
     * (`indent` then sets spaces per nesting level, default 2, and
     * `width` the columns that line must fit in, default 80). Both
     * produce the exact same value on the way back through `decode()`
     * -- `pretty`/`indent`/`width` are a rendering choice, never a
     * semantic one. Throws
     * {@see DXNError} for a value with no DXN representation, or that
     * fails schema validation.
     *
     * Automatically validates every `DXNStruct` or registered
     * application class instance found anywhere in `$value` against
     * its own schema (`$registry`'s compiled schemas) -- pass
     * `validate: false` to skip. A `$schema` name additionally
     * validates `$value` itself against that specific schema -- the
     * one case the automatic walk can't cover on its own, a nameless
     * top-level bare array or unregistered struct.
     *
     * Nothing is converted to make a value match its declared type
     * (DXNS.md §7): a host value always means the same DXN type,
     * schema or not, so a `float` for a `:rational` field is refused
     * rather than turned into a `DXNRational`. Use the `DXN*` classes to
     * say which type a value is.
     *
     * `$deprecations` and `$onDeprecated` are as {@see self::decode()}
     * takes them: a checked struct, type, variant or enum value that is
     * deprecated warns on the way out as on the way in.
     */
    public static function encode(
        mixed $value,
        bool $pretty = false,
        int $indent = 2,
        ?Registry $registry = null,
        bool $validate = true,
        ?string $schema = null,
        int $width = 80,
        ?string $deprecations = null,
        ?callable $onDeprecated = null,
    ): string {
        try {
            $registry = self::withOptions($registry, null, $deprecations, $onDeprecated);
            $validated = self::maybeValidateEncode($value, $registry, $validate, $schema);
            return $pretty
                ? Formatter::pretty($validated, $indent, $registry, $width)
                : Printer::print($validated, $registry);
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'encode');
        }
    }

    /**
     * Decodes a `.dxnb` binary string into a value -- the same value
     * shapes as {@see self::decode()}. Same `$registry` effect. Throws
     * {@see DXNError} on malformed input, including a missing/invalid
     * envelope (`DX` + version byte) or a schema violation. `$strict`,
     * `$deprecations` and `$onDeprecated` as {@see self::decode()} takes
     * them.
     */
    public static function decodeBinary(
        string $bytes,
        ?Registry $registry = null,
        ?bool $strict = null,
        ?string $deprecations = null,
        ?callable $onDeprecated = null,
    ): mixed {
        try {
            return Decoder::decode($bytes, self::withOptions($registry, $strict, $deprecations, $onDeprecated));
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'binary');
        }
    }

    /**
     * Encodes a value to `.dxnb` binary, returning a raw binary
     * string. Same `$registry`/`$validate`/`$schema`
     * parameters as {@see self::encode()}, and its `$deprecations` and
     * `$onDeprecated`. Throws {@see DXNError} for
     * a value with no DXN representation, or that fails schema
     * validation.
     */
    public static function encodeBinary(
        mixed $value,
        ?Registry $registry = null,
        bool $validate = true,
        ?string $schema = null,
        ?string $deprecations = null,
        ?callable $onDeprecated = null,
    ): string {
        try {
            $registry = self::withOptions($registry, null, $deprecations, $onDeprecated);
            $validated = self::maybeValidateEncode($value, $registry, $validate, $schema);
            return Encoder::encode($validated, $registry);
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'binary');
        }
    }

    /**
     * Two independent checks, both applied when requested: the
     * automatic whole-tree walk (every named struct anywhere in
     * `$value`, driven by whether a schema is registered for its
     * name) and, only if `$schema` was given, a check of the result
     * against that one named schema.
     */
    private static function maybeValidateEncode(
        mixed $value,
        ?Registry $registry,
        bool $validate,
        ?string $schema,
    ): mixed {
        $registry ??= new Registry();

        $result = $value;
        if ($validate) {
            $result = Schema::validateEncodeTree($result, $registry);
        }
        if ($schema !== null) {
            $result = Schema::validateEncode($result, $registry, $schema);
        }
        return $result;
    }

    /**
     * `$registry` marked strict when `$strict` is given -- a strict decode
     * refuses extra fields on schema-backed structs (DXNS.md §7) -- and
     * reporting deprecations as `$deprecations` and `$onDeprecated` say,
     * when they are given (DXNS.md §1b).
     */
    private static function withOptions(?Registry $registry, ?bool $strict, ?string $deprecations, ?callable $onDeprecated): ?Registry
    {
        if ($strict !== null) {
            $registry = ($registry ?? new Registry())->putStrict($strict);
        }
        if ($deprecations !== null) {
            $registry = ($registry ?? new Registry())->putDeprecations($deprecations);
        }
        if ($onDeprecated !== null) {
            $registry = ($registry ?? new Registry())->putOnDeprecated($onDeprecated);
        }
        return $registry;
    }
}
