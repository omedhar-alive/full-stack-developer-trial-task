<?php

namespace App\Jobs;

use App\Scraping\ProductScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scrapes one product URL on the queue. Retries, backoff and the failed_jobs
 * record all come from config here, not from code in the scraper.
 */
class ScrapeProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int>|int */
    public array|int $backoff = [10, 30, 60];

    public function __construct(public readonly string $url) {}

    public function handle(ProductScraper $scraper): void
    {
        $scraper->scrape($this->url);
    }
}
