<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeProductJob;
use Illuminate\Console\Command;

class ScrapeRun extends Command
{
    protected $signature = 'scrape:run';

    protected $description = 'Dispatch a scrape job for every configured target (live) or manifest entry (fixture)';

    public function handle(): int
    {
        $urls = $this->urls();

        if ($urls === []) {
            $this->warn('nothing to scrape');

            return self::SUCCESS;
        }

        foreach ($urls as $url) {
            ScrapeProductJob::dispatch($url);
            $this->line("dispatched: {$url}");
        }

        $this->info(count($urls).' job(s) dispatched');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function urls(): array
    {
        if (config('scraping.mode') === 'fixture') {
            $manifestPath = config('scraping.fixtures_path').'/manifest.json';
            $manifest = json_decode((string) @file_get_contents($manifestPath), true);

            return is_array($manifest)
                ? array_values(array_filter(array_column($manifest, 'source_url'), 'is_string'))
                : [];
        }

        return array_values(config('scraping.targets', []));
    }
}
