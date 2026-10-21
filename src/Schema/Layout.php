<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema;

/**
 * The names a `.dxns` file's entries have, from where the file lies under
 * its root (DXNS.md §6) -- one rule for every reader of a tree: the docs
 * generator, {@see Tree} and the file resolver; the port of
 * `Dextrin.Schema.Layout`.
 *
 * - **A file named like one entry holds that entry**: `acme/shop/
 *   Customer-v2.dxns`, `acme/shop/Order/v1.dxns` and `acme.shop.Product.dxns`
 *   hold `acme.shop/Customer`, `acme.shop/Order` and `acme.shop/Product`,
 *   and the version their name says. Every other entry in such a file is
 *   internal to it: `acme.shop.Order/Line`.
 * - **A file holding no entry named like it is a namespace file**:
 *   `acme.common.dxns` holds `acme.common/Money` and the rest.
 * - **A name with no namespace is its own file**: `HealthCheck.dxns`
 *   holding `HealthCheck`.
 */
final class Layout
{
    /**
     * @param array<string, string> $names entry key => full name
     * @param list<string> $internal
     */
    private function __construct(
        public readonly array $names,
        public readonly ?string $holder,
        public readonly ?int $version,
        public readonly array $internal,
    ) {
    }

    /**
     * The layout of the file at `$relative` (to its root, `/`-separated),
     * holding the entries `$keys`.
     *
     * @param list<string> $keys
     */
    public static function of(string $relative, array $keys): self
    {
        [$stem, $version] = self::stem($relative);
        $own = $stem[count($stem) - 1];
        $holder = in_array($own, $keys, true) ? $own : null;
        $namespace = implode('.', array_slice($stem, 0, -1));
        $whole = implode('.', $stem);
        $names = [];
        foreach ($keys as $key) {
            $names[$key] = $key === $holder ? self::join($namespace, $key) : self::join($whole, $key);
        }
        return new self(
            $names,
            $holder,
            $holder !== null ? $version : null,
            $holder !== null ? array_values(array_filter($keys, static fn (string $k): bool => $k !== $holder)) : [],
        );
    }

    /**
     * A file's dotted stem and the version its name says: `Customer-v2`
     * and `Order/v1` carry one, anything else none.
     *
     * @return array{0: non-empty-list<string>, 1: ?int}
     */
    private static function stem(string $relative): array
    {
        $parts = explode('/', (string) preg_replace('/\.dxns$/', '', $relative));
        $last = (string) array_pop($parts);
        $version = null;
        if ($parts !== [] && preg_match('/^v([1-9][0-9]*)$/', $last, $m) === 1) {
            $nameParts = $parts;
            $version = (int) $m[1];
        } elseif (preg_match('/^(.+)-v([1-9][0-9]*)$/', $last, $m) === 1) {
            $nameParts = [...$parts, $m[1]];
            $version = (int) $m[2];
        } else {
            $nameParts = [...$parts, $last];
        }
        $stem = [];
        foreach ($nameParts as $p) {
            foreach (explode('.', $p) as $segment) {
                $stem[] = $segment;
            }
        }
        return [$stem, $version];
    }

    private static function join(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : "{$namespace}/{$name}";
    }
}
