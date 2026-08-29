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
use App\Scraping\ScraperFactory;
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

        // A configured LiveFetcher, always. Fetcher::class returns this in live
        // mode, and ScraperFactory::live() uses it directly for --live.
        $this->app->bind(LiveFetcher::class, fn (Application $app) => new LiveFetcher(
            connectTimeout: $app['config']['scraping.connect_timeout'],
            readTimeout: $app['config']['scraping.read_timeout'],
            maxRetries: $app['config']['scraping.max_retries'],
            maxRedirects: $app['config']['scraping.max_redirects'],
            throttleMs: $app['config']['scraping.throttle_ms'],
        ));

        // Transport chosen by SCRAPER_MODE. bind() (not singleton) so a test
        // that flips config('scraping.mode') gets the matching fetcher.
        $this->app->bind(Fetcher::class, fn (Application $app) => $app['config']['scraping.mode'] === 'fixture'
            ? new FixtureFetcher($app['config']['scraping.fixtures_path'])
            : $app->make(LiveFetcher::class));

        $this->app->bind(ProxyClient::class, fn (Application $app) => new ProxyClient(
            $app['config']['scraping.proxy_service_url'],
            $app['config']['scraping.fallback_user_agents'],
        ));

        $this->app->bind(RobotsChecker::class, fn (Application $app) => new RobotsChecker(
            (bool) $app['config']['scraping.respect_robots'],
        ));

        $this->app->bind(ScraperFactory::class, fn (Application $app) => new ScraperFactory(
            $app->make(ExtractorResolver::class),
            $app->make(ProxyClient::class),
            $app->make(RobotsChecker::class),
            $app->make(Fetcher::class),
            $app->make(LiveFetcher::class),
        ));

        // One source of truth: the plain ProductScraper is the factory's default.
        $this->app->bind(ProductScraper::class, fn (Application $app) => $app->make(ScraperFactory::class)->default());
    }

    public function boot(): void
    {
        //
    }
}
