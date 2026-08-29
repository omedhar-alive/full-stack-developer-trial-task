<?php

namespace App\Providers;

use App\Scraping\Contracts\Fetcher;
use App\Scraping\ExtractorResolver;
use App\Scraping\Extractors\JumiaExtractor;
use App\Scraping\Fetchers\FixtureFetcher;
use App\Scraping\Fetchers\LiveFetcher;
use App\Scraping\ProductScraper;
use App\Scraping\ProxyClient;
use App\Scraping\RobotsChecker;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ExtractorResolver has a variadic constructor — the container cannot
        // autowire it. Left to default resolution it would be built with zero
        // extractors and every URL would throw UnsupportedHostException. The
        // registered extractors must be listed explicitly here.
        $this->app->bind(ExtractorResolver::class, fn (Application $app) => new ExtractorResolver(
            $app->make(JumiaExtractor::class),
        ));

        // Transport is chosen by SCRAPER_MODE. bind() (not singleton) so a test
        // that flips config('scraping.mode') gets the matching fetcher.
        $this->app->bind(Fetcher::class, function (Application $app) {
            $config = $app['config']['scraping'];

            if ($config['mode'] === 'fixture') {
                return new FixtureFetcher($config['fixtures_path']);
            }

            return new LiveFetcher(
                connectTimeout: $config['connect_timeout'],
                readTimeout: $config['read_timeout'],
                maxRetries: $config['max_retries'],
                maxRedirects: $config['max_redirects'],
                throttleMs: $config['throttle_ms'],
            );
        });

        $this->app->bind(ProxyClient::class, fn (Application $app) => new ProxyClient(
            $app['config']['scraping.proxy_service_url'],
            $app['config']['scraping.fallback_user_agents'],
        ));

        $this->app->bind(RobotsChecker::class, fn (Application $app) => new RobotsChecker(
            (bool) $app['config']['scraping.respect_robots'],
        ));

        $this->app->bind(ProductScraper::class, fn (Application $app) => new ProductScraper(
            $app->make(ExtractorResolver::class),
            $app->make(Fetcher::class),
            $app->make(ProxyClient::class),
            $app->make(RobotsChecker::class),
            $app['config']['scraping.mode'],
        ));
    }

    public function boot(): void
    {
        //
    }
}
