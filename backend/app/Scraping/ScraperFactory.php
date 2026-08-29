<?php

namespace App\Scraping;

use App\Scraping\Contracts\Fetcher;
use App\Scraping\Fetchers\LiveFetcher;

/**
 * Builds a ProductScraper in one of two flavours.
 *
 * `default()` follows `SCRAPER_MODE`. `live()` forces live transport regardless
 * of mode — used by `--live` on the commands and by a job dispatched with
 * `live: true`. This is a factory, not runtime config mutation: a queued job
 * resolves its own scraper in the worker process, so the mode has to travel
 * with the job, not sit in shared config.
 */
final class ScraperFactory
{
    public function __construct(
        private readonly ExtractorResolver $resolver,
        private readonly ProxyClient $proxy,
        private readonly RobotsChecker $robots,
        private readonly Fetcher $defaultFetcher,
        private readonly LiveFetcher $liveFetcher,
    ) {}

    public function default(): ProductScraper
    {
        return new ProductScraper(
            $this->resolver,
            $this->defaultFetcher,
            $this->proxy,
            $this->robots,
            (string) config('scraping.mode'),
        );
    }

    public function live(): ProductScraper
    {
        return new ProductScraper(
            $this->resolver,
            $this->liveFetcher,
            $this->proxy,
            $this->robots,
            'live',
        );
    }
}
