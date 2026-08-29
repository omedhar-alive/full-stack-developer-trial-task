<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Price text that could not be converted to minor units. Deliberately free of
 * any scraping vocabulary — it knows about text and numbers, nothing about
 * URLs or selectors. The extractor wraps it in an ExtractionFailedException
 * that adds that context.
 */
class PriceParseException extends RuntimeException
{
    public function __construct(
        public readonly string $rawValue,
        string $reason,
    ) {
        parent::__construct("Could not parse a price from \"{$rawValue}\": {$reason}.");
    }
}
