<?php

namespace App\Scraping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Checks a URL against the target host's robots.txt before a live fetch.
 *
 * Fail OPEN: an unreachable or unparseable robots.txt returns true and logs a
 * warning. Fail-closed would make an unrelated network blip look like a
 * scraping bug and stall every job. The parsed result is cached per host so we
 * fetch robots.txt once an hour, not once a product.
 *
 * No lease is taken and nothing is reported for the robots.txt request — it is
 * not a product fetch. Skipped entirely when respect_robots is false.
 */
final class RobotsChecker
{
    private const CACHE_TTL_SECONDS = 3600;

    private const FETCH_CONNECT_TIMEOUT = 3;

    private const FETCH_READ_TIMEOUT = 5;

    public function __construct(private readonly bool $enabled) {}

    public function allows(string $url): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return true; // nothing to check against — fail open
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';

        $disallow = Cache::remember(
            "robots:{$host}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->loadRules($parts['scheme'].'://'.$host.'/robots.txt'),
        );

        foreach ($disallow as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string> Disallow path prefixes for the `User-agent: *` group
     */
    private function loadRules(string $robotsUrl): array
    {
        try {
            $response = Http::connectTimeout(self::FETCH_CONNECT_TIMEOUT)
                ->timeout(self::FETCH_READ_TIMEOUT)
                ->get($robotsUrl);

            if (! $response->successful()) {
                Log::warning('robots.txt unreadable; allowing', ['url' => $robotsUrl, 'status' => $response->status()]);

                return [];
            }

            return $this->parse($response->body());
        } catch (\Throwable $e) {
            Log::warning('robots.txt unreachable; allowing', ['url' => $robotsUrl, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function parse(string $body): array
    {
        $rules = [];
        $appliesToUs = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                $appliesToUs = $value === '*';
            } elseif ($field === 'disallow' && $appliesToUs) {
                $rules[] = $value;
            }
        }

        return $rules;
    }
}
