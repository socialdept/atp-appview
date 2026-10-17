<?php

namespace SocialDept\AtpAppview\Ingest\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A batch kept failing after every retry. Nothing in it was acknowledged, so
 * Tap redelivers it.
 */
class BatchFailed extends RuntimeException
{
    public function __construct(public readonly int $attempts, Throwable $previous)
    {
        parent::__construct("Batch failed after {$attempts} attempt(s): {$previous->getMessage()}", 0, $previous);
    }
}
