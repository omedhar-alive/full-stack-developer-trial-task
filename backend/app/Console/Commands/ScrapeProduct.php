<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeProductJob;
use App\Scraping\ProductScraper;
use Illuminate\Console\Command;

class ScrapeProduct extends Command
{
    protected $signature = 'scrape:product {url : The product page URL} {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Scrape one product URL';

    public function handle(ProductScraper $scraper): int
    {
        $url = $this->argument('url');

        if ($this->option('sync')) {
            $product = $scraper->scrape($url);
            $this->info($product
                ? "saved #{$product->id}: {$product->title}"
                : 'no product persisted (out of stock, or skipped by robots.txt)');

            return self::SUCCESS;
        }

        ScrapeProductJob::dispatch($url);
        $this->info("dispatched: {$url}");

        return self::SUCCESS;
    }
}
