<?php

namespace App\Jobs;

use App\Scraping\ScraperFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scrapes one product URL on the queue. Retries, backoff and the failed_jobs
 * record all come from config here, not from code in the scraper.
 *
 * $live travels with the job: the worker resolves the scraper in its own
 * process, so a job dispatched by `--live` must carry the mode rather than
 * rely on the worker container's SCRAPER_MODE (which is fixture).
 */
class ScrapeProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int>|int */
    public array|int $backoff = [10, 30, 60];

    public function __construct(
        public readonly string $url,
        public readonly bool $live = false,
    ) {}

    public function handle(ScraperFactory $factory): void
    {
        ($this->live ? $factory->live() : $factory->default())->scrape($this->url);
    }
}
