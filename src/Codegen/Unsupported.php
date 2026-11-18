<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Codegen;

/**
 * A form the generator does not write yet ({@see Php}), raised where it
 * is met; the field and the version are added on the way out, so the
 * refusal names where it stands.
 *
 * @internal
 */
final class Unsupported extends \RuntimeException
{
    /**
     * @param string $message what is not generated, as DXNS writes it
     * @param int|null $version the schema version it stands in, once known
     */
    public function __construct(string $message, public readonly ?int $version = null)
    {
        parent::__construct($message);
    }
}
