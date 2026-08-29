<?php

namespace App\Scraping\Contracts;

use App\Exceptions\ExtractionFailedException;
use App\Scraping\ProductData;
use Symfony\Component\DomCrawler\Crawler;

interface SiteExtractor
{
    /**
     * Whether this extractor handles the given URL host, e.g. "www.jumia.com.eg".
     */
    public function supports(string $host): bool;

    /**
     * Parse one product page.
     *
     * @param  string  $sourceUrl  the page's own URL. The extractor is the only
     *                             place that knows how to turn that site's
     *                             relative image URLs into absolute ones.
     * @return ProductData|null null ONLY when the item is legitimately out of
     *                          stock (no price on the page). Every other
     *                          missing or malformed field — including price
     *                          text that will not parse — throws.
     *
     * @throws ExtractionFailedException
     */
    public function extract(Crawler $html, string $sourceUrl): ?ProductData;
}
