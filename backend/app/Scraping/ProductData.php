<?php

namespace App\Scraping;

use InvalidArgumentException;

/**
 * Immutable result of parsing one product page. This is the boundary between
 * parsing and persistence: a missing or malformed field fails here, in the
 * extractor's call, not as a silent null three layers later in the job.
 *
 * Fields mirror the products table minus id and timestamps.
 */
final readonly class ProductData
{
    public function __construct(
        public string $title,
        public int $priceMinor,
        public string $currency,
        public string $imageUrl,
        public string $sourceUrl,
    ) {
        if (trim($title) === '') {
            throw new InvalidArgumentException('ProductData: title is empty.');
        }

        if ($priceMinor < 0) {
            throw new InvalidArgumentException("ProductData: priceMinor is negative ({$priceMinor}).");
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(
                "ProductData: currency must be a 3-letter ISO 4217 code, got \"{$currency}\"."
            );
        }

        if (trim($imageUrl) === '') {
            throw new InvalidArgumentException('ProductData: imageUrl is empty.');
        }

        if (! filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("ProductData: sourceUrl is not a valid URL (\"{$sourceUrl}\").");
        }
    }
}
