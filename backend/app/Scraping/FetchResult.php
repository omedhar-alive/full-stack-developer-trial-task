<?php

namespace App\Scraping;

/**
 * The outcome of one page fetch, whatever the transport. statusCode is null
 * only when no response was received (live mode, connection failure); fixture
 * mode always reports 200.
 */
final readonly class FetchResult
{
    public function __construct(
        public string $html,
        public ?int $statusCode,
        public int $latencyMs,
    ) {}

    public function ok(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
