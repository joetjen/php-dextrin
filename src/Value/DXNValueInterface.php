<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Value;

/**
 * Implemented by every `DXN*` wrapper class -- the PHP analog of the
 * `dxnType` string tag used for kind dispatch in the JS port, and of
 * `Dextrin.Value`'s closed type union in the Elixir original. Native
 * PHP values (`null`, `bool`, `int`, `float`, `string`, and `array`
 * for `map`/`array`) never implement this; {@see Equality::kindOf()}
 * handles those directly.
 */
interface DXNValueInterface
{
    /** The DXN type name this value represents, e.g. "symbol", "uuid". */
    public function dxnType(): string;

    /** Structural equality, recursing into nested DXN values as needed. */
    public function equals(mixed $other): bool;

    /**
     * A total order against any other DXN value, not just another
     * instance of this class -- backs {@see DXNSortedSet}. Cross-kind
     * ordering is implementation-defined per DXN.md; within a kind,
     * uses the kind's own natural order where one exists, or a
     * deterministic structural fallback where it doesn't.
     */
    public function compareTo(mixed $other): int;
}
