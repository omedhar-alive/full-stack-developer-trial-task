<?php

namespace App\Scraping;

/**
 * Builds the Guzzle option array for a target fetch from a Lease plus timeout
 * config. Pure and framework-free on purpose: it is the only place the
 * "proxy key present only for a real string URL" rule lives, and the only way
 * to assert it — Http::fake() does not expose request options to test
 * assertions, so this has to be unit-testable without the container.
 */
final class RequestOptions
{
    /**
     * @return array<string, mixed> passed straight to Http::withOptions()
     */
    public static function for(
        Lease $lease,
        int $connectTimeout,
        int $readTimeout,
        int $maxRedirects,
    ): array {
        $options = [
            'connect_timeout' => $connectTimeout,
            'timeout' => $readTimeout,
            'allow_redirects' => ['max' => $maxRedirects],
            'headers' => [
                'User-Agent' => $lease->userAgent,
            ],
        ];

        // Only a non-empty string is a proxy. null / "" mean "direct" and the
        // key is omitted — Guzzle 8 validates this option and rejects a
        // non-string, non-array value.
        if (is_string($lease->proxyUrl) && $lease->proxyUrl !== '') {
            $options['proxy'] = $lease->proxyUrl;
        }

        return $options;
    }
}
