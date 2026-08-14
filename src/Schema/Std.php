<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Text\Parser;

/**
 * This library's standard library of named types
 * (`priv/schema/std.dxns`, ported verbatim from `dextrin`'s own) --
 * common refinements like `PositiveInteger`/`NonEmptyString`, so a
 * schema author doesn't redefine them by hand. Built the same way any
 * consumer's own named types would be -- nothing about them is
 * special-cased in {@see Compiler}. Deliberately excludes anything
 * domain-specific (email, phone number, URL-shaped string): what
 * counts as a valid one is an application decision this library
 * shouldn't guess at.
 *
 * Opt-in: pass {@see self::registry()}'s result as
 * {@see \JOetjen\Dextrin\Schema}'s `compile()` `$baseRegistry` to make
 * these names available to your own document.
 *
 *     $value = Dextrin::decode($myDxnsSource);
 *     $registry = Std::registry();
 *     $compiled = Schema::compile($value, $registry);
 */
final class Std
{
    private const SOURCE_PATH = __DIR__ . '/../../priv/schema/std.dxns';

    private function __construct()
    {
    }

    /** The standard library's own `.dxns` source. */
    public static function source(): string
    {
        $contents = file_get_contents(self::SOURCE_PATH);
        if ($contents === false) {
            throw new \RuntimeException('failed to read ' . self::SOURCE_PATH);
        }
        return $contents;
    }

    /**
     * Compiles the standard library into `$baseRegistry` (a fresh
     * {@see Registry} by default), returning its named types merged in
     * alongside anything `$baseRegistry` already had.
     */
    public static function registry(?Registry $baseRegistry = null): Registry
    {
        $doc = Parser::parse(self::source());
        return Compiler::compile($doc, $baseRegistry ?? new Registry());
    }
}
