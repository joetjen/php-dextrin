<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

/**
 * The three forms' names on the wire and on disk, and a cheap test of
 * whether a body is worth decoding as one -- DXN.md §6, mirroring
 * `Dextrin.Format`, so every implementation answers the same.
 *
 * `identifies()` is a prefix test, not a parse: `true` means "worth
 * attempting", never "valid". Its text rule mainly tells DXN from JSON in
 * a body that comes without a type, so it accepts only what JSON cannot
 * start with: after whitespace, comments and an optional header, a `%` --
 * a map, a struct or a schema document. A list or a scalar is DXN too, but
 * it overlaps with JSON, and stays unrecognised on purpose (Jan,
 * 2026-10-20).
 */
final class Format
{
    private function __construct()
    {
    }

    /** The media type of `$form`. */
    public static function mediaType(Form $form): string
    {
        return match ($form) {
            Form::Text => 'application/vnd.dxn',
            Form::Binary => 'application/vnd.dxnb',
            Form::Schema => 'application/vnd.dxns',
        };
    }

    /** The file extension of `$form`, with its dot. */
    public static function extension(Form $form): string
    {
        return match ($form) {
            Form::Text => '.dxn',
            Form::Binary => '.dxnb',
            Form::Schema => '.dxns',
        };
    }

    /**
     * The form `$mediaType` names: one of the three, or text for any type
     * with a structured `+dxn` suffix (`application/vnd.acme.event+dxn`);
     * null for anything else. There is no `+dxnb` suffix. Media types
     * compare without regard to case; the caller strips any parameters
     * (`; charset=...`) first.
     */
    public static function form(string $mediaType): ?Form
    {
        $type = strtolower($mediaType);
        foreach (Form::cases() as $form) {
            if (self::mediaType($form) === $type) {
                return $form;
            }
        }
        return preg_match('#^[^/\s;]+/[^/\s;+]+[^/\s;]*\+dxn$#', $type) === 1 ? Form::Text : null;
    }

    /**
     * Whether `$body` is worth decoding as `$form`. Binary: the envelope's
     * magic, `DX`, and a version byte (DXN.md §2.1). Text and schemas: after
     * whitespace (commas included), `#` comments and an optional
     * `@dxn "..."` header, the first character is `%`.
     */
    public static function identifies(string $body, Form $form): bool
    {
        if ($form === Form::Binary) {
            return strlen($body) >= 3 && str_starts_with($body, 'DX');
        }
        $rest = self::skipTrivia((string) preg_replace('/^@dxn[ \t\n\r,]*"[^"\n]*"/', '', self::skipTrivia($body)));
        return str_starts_with($rest, '%');
    }

    /** `$text` without its leading whitespace and comments. */
    private static function skipTrivia(string $text): string
    {
        return (string) preg_replace('/^(?:[ \t\n\r,]|#[^\n]*)*/', '', $text);
    }
}
