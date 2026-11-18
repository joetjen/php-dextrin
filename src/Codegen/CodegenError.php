<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Codegen;

/**
 * Code could not be generated ({@see Php}): the schemas do not load, a
 * schema uses a form the generator does not write yet, or two schemas
 * would be given one class. Every problem is listed, each naming its
 * schema (and its file, version and field, where it has them); nothing
 * is written while there is one.
 */
final class CodegenError extends \RuntimeException
{
    /**
     * @param list<string> $problems every problem found, in words
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode("\n", $problems));
    }
}
