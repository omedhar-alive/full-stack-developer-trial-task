<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A required field could not be found or made sense of on a product page.
 * The scrape fails loudly rather than persisting a half-populated row.
 *
 * Every message names both the source URL and the selector / field that
 * failed — a failed_jobs row that only says "could not parse price" is not
 * actionable.
 */
class ExtractionFailedException extends RuntimeException
{
    public static function missing(string $sourceUrl, string $selector): self
    {
        return new self("Extraction failed for {$sourceUrl}: required data \"{$selector}\" was not found.");
    }

    public static function unparsable(string $sourceUrl, string $selector, Throwable $previous): self
    {
        return new self(
            "Extraction failed for {$sourceUrl}: \"{$selector}\" could not be parsed: {$previous->getMessage()}",
            0,
            $previous,
        );
    }
}
