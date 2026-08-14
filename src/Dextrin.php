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
     */
    public static function decode(string $text, ?Registry $registry = null): mixed
    {
        try {
            return Parser::parse($text, $registry);
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'parse');
        }
    }

    /**
     * Encodes a value back to `.dxn` text. Single-line,
     * minimal-whitespace by default; pass `pretty: true` for
     * multi-line, indented output instead (`indent` then sets spaces
     * per nesting level, default 2). Both produce the exact same
     * value on the way back through `decode()` -- `pretty`/`indent`
     * are a rendering choice, never a semantic one. Throws
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
     * `$coerce` (default `true`) lets a field whose value doesn't
     * already match its declared type be coerced toward it first (a
     * plain `float` becoming a `DXNRational` for a `:rational` field,
     * say) -- see {@see \JOetjen\Dextrin\Schema\Coercion}'s own doc;
     * this has no Elixir `dextrin` equivalent. Pass `coerce: false`
     * for strict validation with no silent conversion.
     */
    public static function encode(
        mixed $value,
        bool $pretty = false,
        int $indent = 2,
        ?Registry $registry = null,
        bool $validate = true,
        bool $coerce = true,
        ?string $schema = null,
    ): string {
        try {
            $validated = self::maybeValidateEncode($value, $registry, $validate, $coerce, $schema);
            return $pretty ? Formatter::pretty($validated, $indent, $registry) : Printer::print($validated, $registry);
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'encode');
        }
    }

    /**
     * Decodes a `.dxnb` binary string into a value -- the same value
     * shapes as {@see self::decode()}. Same `$registry` effect. Throws
     * {@see DXNError} on malformed input, including a missing/invalid
     * envelope (`DX` + version byte) or a schema violation.
     */
    public static function decodeBinary(string $bytes, ?Registry $registry = null): mixed
    {
        try {
            return Decoder::decode($bytes, $registry);
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'binary');
        }
    }

    /**
     * Encodes a value to `.dxnb` binary, returning a raw binary
     * string. Same `$registry`/`$validate`/`$coerce`/`$schema`
     * parameters as {@see self::encode()}. Throws {@see DXNError} for
     * a value with no DXN representation, or that fails schema
     * validation.
     */
    public static function encodeBinary(
        mixed $value,
        ?Registry $registry = null,
        bool $validate = true,
        bool $coerce = true,
        ?string $schema = null,
    ): string {
        try {
            $validated = self::maybeValidateEncode($value, $registry, $validate, $coerce, $schema);
            return Encoder::encode($validated, $registry);
        } catch (\Throwable $err) {
            throw DXNError::wrap($err, 'binary');
        }
    }

    /**
     * Two independent checks, both applied when requested: the
     * automatic whole-tree walk (every named struct anywhere in
     * `$value`, driven by whether a schema is registered for its
     * name) and, only if `$schema` was given, a check of the
     * (possibly already-coerced) result against that one named
     * schema. Both may return a *transformed* value when `$coerce`
     * (default `true`) lets a mismatched field be coerced toward its
     * declared type -- see {@see self::encode()}'s own doc.
     */
    private static function maybeValidateEncode(
        mixed $value,
        ?Registry $registry,
        bool $validate,
        bool $coerce,
        ?string $schema,
    ): mixed {
        $registry ??= new Registry();

        $result = $value;
        if ($validate) {
            $result = Schema::validateEncodeTree($result, $registry, $coerce);
        }
        if ($schema !== null) {
            $result = Schema::validateEncode($result, $registry, $schema, $coerce);
        }
        return $result;
    }
}
