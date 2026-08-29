<?php

namespace App\Scraping;

/**
 * One handout from the Go proxy-manager, or a local substitute for it.
 *
 * A real lease has a non-null leaseId and must be reported back. A fallback
 * lease — produced when the Go service is unreachable — has leaseId null, which
 * is exactly what tells ProxyClient::report() to do nothing.
 */
final readonly class Lease
{
    public function __construct(
        public ?string $leaseId,
        public ?string $proxyUrl,
        public string $userAgent,
        public bool $fromFallback,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the GET /lease 200 body
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            leaseId: is_string($payload['lease_id'] ?? null) ? $payload['lease_id'] : null,
            proxyUrl: is_string($payload['proxy_url'] ?? null) ? $payload['proxy_url'] : null,
            userAgent: (string) ($payload['user_agent'] ?? ''),
            fromFallback: false,
        );
    }

    public static function fallback(string $userAgent): self
    {
        return new self(null, null, $userAgent, true);
    }

    public function isReportable(): bool
    {
        return $this->leaseId !== null;
    }
}
