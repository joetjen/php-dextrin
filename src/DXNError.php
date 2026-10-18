<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

/**
 * One exception type for every `dextrin` failure mode -- text-parse,
 * printing, and (in later phases) binary/schema failures alike -- so
 * a caller never has to special-case which pipeline produced it.
 * Unlike Elixir's `{:ok, _} | {:error, %Dextrin.Error{}}` tuples,
 * throwing here is simply idiomatic PHP from the start (same as the
 * JS port's own deliberate divergence, for the same reason) -- no
 * "divergence from tuples" note is needed the way the JS port's own
 * `DXNError` doc carries one.
 */
final class DXNError extends \RuntimeException
{
    public readonly string $stage;
    public readonly ?int $offset;

    /**
     * What kind of error this is, as DXN.md §5 names it -- `syntax`,
     * `binary`, `encode/unsupported`, ... -- so errors compare across
     * implementations without comparing messages. Null where none has
     * been assigned yet.
     */
    public readonly ?string $category;

    public function __construct(
        string $message,
        string $stage = 'unknown',
        ?int $offset = null,
        ?\Throwable $previous = null,
        ?string $category = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->stage = $stage;
        $this->offset = $offset;
        $this->category = $category;
    }

    /**
     * Wraps any thrown exception from the text/binary pipelines into a
     * `DXNError`, preserving a lexer/parser's own `offset` when
     * present (via the `HasOffset` interface) and passing everything
     * else through as the previous exception -- callers only ever
     * need to catch one exception type from `Dextrin::decode()`/
     * `Dextrin::encode()`.
     */
    public static function wrap(\Throwable $err, string $stage): self
    {
        if ($err instanceof self) {
            return $err;
        }
        $offset = $err instanceof HasOffset ? $err->offset() : null;
        $category = $err instanceof HasCategory ? $err->category() : null;
        return new self($err->getMessage(), $stage, $offset, $err, $category);
    }
}
