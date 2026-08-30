<?php

namespace App\Scraping;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the Go proxy-manager (CONTRACTS.md §3) over Laravel's HTTP client,
 * which wraps Guzzle. Every failure mode here is degraded, never fatal: if the
 * Go service is down the scrape still runs, it just loses health scoring and
 * rotation. That is the "in the request path with a degraded fallback"
 * property — normal operation goes through Go, losing it costs the pool's
 * feedback loop, not the ability to scrape.
 *
 * Timeouts are deliberately short: this is a localhost call and must never
 * dominate the per-scrape budget.
 */
final class ProxyClient
{
    private const CONNECT_TIMEOUT = 2;

    private const READ_TIMEOUT = 3;

    private int $fallbackCursor = 0;

    /**
     * @param  list<string>  $fallbackUserAgents
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $fallbackUserAgents,
    ) {}

    /**
     * GET /lease. A 200 with a usable payload becomes a real Lease. Anything
     * else — non-200, 503, malformed JSON, connection failure — becomes a
     * fallback Lease and a logged warning. Never throws.
     */
    public function lease(): Lease
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::READ_TIMEOUT)
                ->get($this->url('/lease'));

            if (! $response->successful()) {
                return $this->fallback("HTTP {$response->status()} from /lease");
            }

            $payload = $response->json();
            if (! is_array($payload) || ! is_string($payload['user_agent'] ?? null) || ($payload['user_agent'] ?? '') === '') {
                return $this->fallback('malformed /lease payload');
            }

            return Lease::fromPayload($payload);
        } catch (ConnectionException $e) {
            return $this->fallback("could not reach /lease: {$e->getMessage()}");
        }
    }

    /**
     * POST /report. No-op for a fallback lease (nothing to report against).
     * Any failure is logged and swallowed — losing health data must not fail a
     * scrape that otherwise worked.
     *
     * @param  ?int  $statusCode  the target's HTTP status, or null when no
     *                            response was received (reported as 0)
     */
    public function report(Lease $lease, ?int $statusCode, bool $ok, int $latencyMs): void
    {
        if (! $lease->isReportable()) {
            return;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::READ_TIMEOUT)
                ->post($this->url('/report'), [
                    'lease_id' => $lease->leaseId,
                    'ok' => $ok,
                    'status_code' => $statusCode ?? 0,
                    'latency_ms' => $latencyMs,
                ]);

            if (! $response->successful()) {
                // Laravel's HTTP client does not throw on 4xx/5xx, so a 400
                // (malformed body) or 404 (unknown, already-reported or reaped
                // lease) would otherwise vanish. The health data is already
                // lost; log it, but still do not fail the scrape.
                Log::warning('proxy report rejected; continuing', [
                    'lease_id' => $lease->leaseId,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('proxy report failed; continuing', [
                'lease_id' => $lease->leaseId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fallback(string $reason): Lease
    {
        Log::warning('proxy lease unavailable; using local user-agent fallback', ['reason' => $reason]);

        $agents = $this->fallbackUserAgents;
        $agent = $agents[$this->fallbackCursor % count($agents)];
        $this->fallbackCursor++;

        return Lease::fallback($agent);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
