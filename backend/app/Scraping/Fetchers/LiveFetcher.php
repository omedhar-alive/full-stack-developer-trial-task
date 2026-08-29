<?php

namespace App\Scraping\Fetchers;

use App\Scraping\Contracts\Fetcher;
use App\Scraping\FetchResult;
use App\Scraping\Lease;
use App\Scraping\RequestOptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Fetches the live page through Laravel's HTTP client (Guzzle underneath),
 * routed via the lease's proxy/user-agent. Throttles first with
 * Illuminate\Support\Sleep — fakeable in tests, unlike usleep(), so tests never
 * actually wait — then retries connection failures and 5xx with exponential
 * backoff.
 *
 * A persistent failure propagates: ConnectionException when no response was
 * received, RequestException (carrying the response) when the last attempt
 * returned a failure status. ProductScraper reports both to Go before rethrow.
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
            function (\Throwable $e) {
                // Retry connection failures and 5xx only.
                //
                // 4xx is never retried: a 403 will not become a 200 in ~200ms,
                // and retrying just hammers a site that has already blocked us —
                // the opposite of the throttling claim. 429 in particular needs
                // a wait far longer than a retry loop provides; the circuit
                // breaker is the right mechanism there — Go benches the identity
                // on the reported 429.
                if ($e instanceof ConnectionException) {
                    return true;
                }
                if ($e instanceof RequestException) {
                    return $e->response->serverError(); // 5xx only
                }

                return false;
            },
            throw: true,
        )->get($url);

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return new FetchResult($response->body(), $response->status(), $latencyMs);
    }
}
