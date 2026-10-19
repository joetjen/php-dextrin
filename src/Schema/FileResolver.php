<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Text\Parser;

/**
 * An optional convenience resolver ({@see Registry::putResolver()})
 * resolving `Namespace/Name` references to `.dxns` files on disk.
 *
 * Composing multiple compiled schemas into one registry needs no
 * special mechanism at all -- {@see Compiler}'s `$baseRegistry`
 * parameter threads across repeated calls. What genuinely needs a
 * policy decision is *automatically* resolving a bare `Namespace/Name`
 * reference to a file path, and this class is *one* reasonable,
 * swappable answer to that -- not a mandated convention baked into
 * `Registry`/`Schema` itself. Anyone with a different convention
 * writes their own resolver closure directly against
 * `Registry::putResolver()`; this class exists only because the
 * convention below is a common, unsurprising default.
 *
 * **Convention**: `Namespace/Name` resolves to
 * `<searchPath>/Namespace.dxns`, read for the specific entry named
 * `Name` -- a `.dxns` file is already a map of possibly-many schema
 * names, so "Namespace" names the *file*, "Name" one schema defined
 * inside it. A bare reference with no `/` (`Name`) resolves to
 * `<searchPath>/Name.dxns`, read for the entry also named `Name`.
 */
final class FileResolver
{
    private function __construct()
    {
    }

    /**
     * Builds a resolver searching `$searchPaths` in order, returning
     * the first match.
     *
     * @param list<string> $searchPaths
     * @return \Closure(string): ?Compiled
     */
    public static function forPaths(array $searchPaths): \Closure
    {
        return static function (string $name) use ($searchPaths): ?Compiled {
            [$fileStem, $entryName] = self::splitName($name);
            foreach ($searchPaths as $dir) {
                $found = self::resolveIn($dir, $fileStem, $entryName);
                if ($found !== null) {
                    return $found;
                }
            }
            return null;
        };
    }

    private static function resolveIn(string $dir, string $fileStem, string $entryName): ?Compiled
    {
        $filePath = $dir . '/' . $fileStem . '.dxns';
        if (!is_file($filePath)) {
            return null;
        }
        try {
            $source = file_get_contents($filePath);
            if ($source === false) {
                return null;
            }
            $doc = Parser::parse($source);
            $registry = Compiler::compile($doc, new Registry());
            return $registry->fetchStructSchema($entryName)?->compiled;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: string, 1: string} */
    private static function splitName(string $name): array
    {
        $slash = strpos($name, '/');
        return $slash === false ? [$name, $name] : [substr($name, 0, $slash), substr($name, $slash + 1)];
    }
}
