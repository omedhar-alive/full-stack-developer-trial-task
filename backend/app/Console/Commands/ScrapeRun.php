<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeProductJob;
use App\Scraping\ScraperFactory;
use Illuminate\Console\Command;

class ScrapeRun extends Command
{
    protected $signature = 'scrape:run
        {--sync : Run each scrape inline and exit non-zero if any failed}
        {--live : Iterate config(scraping.targets) and fetch over the network regardless of SCRAPER_MODE}';

    protected $description = 'Scrape every configured target (live) or manifest entry (fixture)';

    public function handle(ScraperFactory $factory): int
    {
        $live = (bool) $this->option('live');
        $urls = $this->urls($live);

        if ($urls === []) {
            $this->warn('nothing to scrape');

            return self::SUCCESS;
        }

        if (! $this->option('sync')) {
            foreach ($urls as $url) {
                ScrapeProductJob::dispatch($url, $live);
                $this->line(($live ? 'dispatched (live): ' : 'dispatched: ').$url);
            }
            $this->info(count($urls).' job(s) dispatched');

            return self::SUCCESS;
        }

        $scraper = $live ? $factory->live() : $factory->default();
        $failed = 0;
        foreach ($urls as $url) {
            try {
                $product = $scraper->scrape($url);
                $this->line(($product ? 'saved: ' : 'no row: ').$url);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("failed: {$url} — {$e->getMessage()}");
            }
        }
        $this->info(count($urls).' scraped, '.$failed.' failed');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * --live and live mode both use the configured target list; fixture mode
     * without --live uses the manifest.
     *
     * @return list<string>
     */
    private function urls(bool $live): array
    {
        if ($live || config('scraping.mode') === 'live') {
            return array_values(config('scraping.targets', []));
        }

        $manifestPath = config('scraping.fixtures_path').'/manifest.json';
        $manifest = json_decode((string) @file_get_contents($manifestPath), true);

        return is_array($manifest)
            ? array_values(array_filter(array_column($manifest, 'source_url'), 'is_string'))
            : [];
    }
}
