<?php

namespace App\Scraping;

use App\Exceptions\UnsupportedHostException;
use App\Scraping\Contracts\SiteExtractor;

/**
 * Picks the extractor for a URL by its host. An unsupported host is a typed
 * failure, never a silent null. Today the only registered extractor is
 * JumiaExtractor; a second site is one more constructor argument.
 */
final class ExtractorResolver
{
    /** @var list<SiteExtractor> */
    private array $extractors;

    public function __construct(SiteExtractor ...$extractors)
    {
        $this->extractors = array_values($extractors);
    }

    public function forUrl(string $url): SiteExtractor
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new UnsupportedHostException($url);
        }

        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($host)) {
                return $extractor;
            }
        }

        throw new UnsupportedHostException($host);
    }
}
