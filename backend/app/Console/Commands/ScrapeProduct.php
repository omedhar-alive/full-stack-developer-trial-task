<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeProductJob;
use App\Scraping\ScraperFactory;
use Illuminate\Console\Command;

class ScrapeProduct extends Command
{
    protected $signature = 'scrape:product
        {url : The product page URL}
        {--sync : Run inline instead of dispatching to the queue}
        {--live : Fetch over the network regardless of SCRAPER_MODE}';

    protected $description = 'Scrape one product URL';

    public function handle(ScraperFactory $factory): int
    {
        $url = $this->argument('url');
        $live = (bool) $this->option('live');

        if ($this->option('sync')) {
            $product = ($live ? $factory->live() : $factory->default())->scrape($url);
            $this->info($product
                ? "saved #{$product->id}: {$product->title}"
                : 'no product persisted (out of stock, or skipped by robots.txt)');

            return self::SUCCESS;
        }

        ScrapeProductJob::dispatch($url, $live);
        $this->info(($live ? 'dispatched (live): ' : 'dispatched: ').$url);

        return self::SUCCESS;
    }
}
