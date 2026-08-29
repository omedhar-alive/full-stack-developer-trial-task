<?php

namespace App\Scraping\Fetchers;

use App\Scraping\Contracts\Fetcher;
use App\Scraping\FetchResult;
use App\Scraping\Lease;
use App\Scraping\RequestOptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Fetches the live page through Laravel's HTTP client (Guzzle underneath),
 * routed via the lease's proxy/user-agent. Throttles first with
 * Illuminate\Support\Sleep — fakeable in tests, unlike usleep(), so tests never
 * actually wait — then retries with exponential backoff. A persistent
 * connection failure propagates out as ConnectionException.
 */
final class LiveFetcher implements Fetcher
{
    public function __construct(
        private readonly int $connectTimeout,
        private readonly int $readTimeout,
        private readonly int $maxRetries,
        private readonly int $maxRedirects,
        private readonly int $throttleMs,
    ) {}

    public function fetch(string $url, ?Lease $lease = null): FetchResult
    {
        if ($lease === null) {
            throw new \InvalidArgumentException('LiveFetcher requires a lease.');
        }

        if ($this->throttleMs > 0) {
            Sleep::for($this->throttleMs)->milliseconds();
        }

        $startedAt = hrtime(true);

        $response = Http::withOptions(
            RequestOptions::for($lease, $this->connectTimeout, $this->readTimeout, $this->maxRedirects)
        )->retry(
            $this->maxRetries + 1,
            fn (int $attempt) => (int) (100 * (2 ** ($attempt - 1))),
            throw: true,
        )->get($url);

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return new FetchResult($response->body(), $response->status(), $latencyMs);
    }
}
