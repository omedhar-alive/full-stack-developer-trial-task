<?php

namespace App\Scraping\Fetchers;

use App\Scraping\Contracts\Fetcher;
use App\Scraping\FetchResult;
use App\Scraping\Lease;

/**
 * Reads saved HTML from resources/fixtures/, chosen by matching the requested
 * URL against manifest.json's source_url. No network, no sleep, no lease. The
 * same extractor/resolver/parser/persistence path runs on the result — only the
 * transport is swapped (CONTRACTS.md §4, "Fixture mode").
 */
final class FixtureFetcher implements Fetcher
{
    public function __construct(private readonly string $fixturesPath) {}

    public function fetch(string $url, ?Lease $lease = null): FetchResult
    {
        foreach ($this->manifest() as $entry) {
            if (($entry['source_url'] ?? null) === $url) {
                $path = $this->fixturesPath.DIRECTORY_SEPARATOR.($entry['file'] ?? '');
                $html = @file_get_contents($path);
                if ($html === false) {
                    throw new \RuntimeException("Fixture file missing: {$path}");
                }

                return new FetchResult($html, 200, 0);
            }
        }

        throw new \RuntimeException("No fixture in manifest for URL: {$url}");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manifest(): array
    {
        $path = $this->fixturesPath.DIRECTORY_SEPARATOR.'manifest.json';
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Fixture manifest missing: {$path}");
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new \RuntimeException("Fixture manifest is not a JSON array: {$path}");
        }

        return $data;
    }
}
