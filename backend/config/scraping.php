<?php

// The ONLY place env() is read for the scraper. Everywhere else reads this via
// config('scraping.*') — once config:cache runs, env() returns null outside
// config files.

return [

    // 'fixture' | 'live'. Fixture reads saved HTML from resources/fixtures/ and
    // makes no network request, no lease, no report (CONTRACTS.md §4).
    'mode' => env('SCRAPER_MODE', 'fixture'),

    // Go proxy-manager base URL (CONTRACTS.md §2).
    'proxy_service_url' => env('PROXY_SERVICE_URL', 'http://proxy:8080'),

    // Guzzle timeouts, in seconds.
    'connect_timeout' => (int) env('SCRAPER_CONNECT_TIMEOUT', 15),
    'read_timeout' => (int) env('SCRAPER_READ_TIMEOUT', 15),

    // Retry attempts beyond the first, and the redirect-chain cap.
    'max_retries' => (int) env('SCRAPER_MAX_RETRIES', 2),
    'max_redirects' => (int) env('SCRAPER_MAX_REDIRECTS', 3),

    // Politeness delay between live requests, milliseconds.
    'throttle_ms' => (int) env('SCRAPER_THROTTLE_MS', 2000),

    // Check robots.txt before a live fetch. Skipped entirely when false.
    'respect_robots' => filter_var(env('SCRAPER_RESPECT_ROBOTS', true), FILTER_VALIDATE_BOOL),

    // Attempt a live scrape of `targets` at container start, after the fixture
    // seed. Off makes boot fully offline.
    'seed_live' => filter_var(env('SCRAPER_SEED_LIVE', true), FILTER_VALIDATE_BOOL),

    // Where fixture mode reads HTML and manifest.json from.
    'fixtures_path' => resource_path('fixtures'),

    // Used when the Go service is unreachable: only the user-agent rotates, the
    // transport stays direct. Real proxies would be added to proxies.json on the
    // Go side, not here.
    'fallback_user_agents' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64; rv:141.0) Gecko/20100101 Firefox/141.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Safari/605.1.15',
    ],

    // Live-mode target list for `php artisan scrape:run`. Fixture mode ignores
    // this and iterates resources/fixtures/manifest.json instead.
    'targets' => [
        'https://www.jumia.com.eg/apple-iphone-17-pro-max-6.9-256gb-rom-ios-26-5g-cosmic-orange-134276913.html',
        'https://www.jumia.com.eg/galaxy-a07-dual-sim-4g-128gb4gb-mobile-phone-light-violet-samsung-mpg3859614.html',
        'https://www.jumia.com.eg/honor-x6c-dual-sim-4g-128gb6gb-ocean-cyan-134895074.html',
        'https://www.jumia.com.eg/infinix-x6840b-64gb4gb-smart-20-mobile-phone-with-charger-cloudline-blue-134515921.html',
    ],

];
