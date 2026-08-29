<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The resolver was asked for an extractor for a host no registered extractor
 * supports. A typed failure, never a silent null.
 */
class UnsupportedHostException extends RuntimeException
{
    public function __construct(public readonly string $host)
    {
        parent::__construct("No extractor is registered for host \"{$host}\".");
    }
}
