<?php

use App\Jobs\ScrapeProductJob;
use App\Models\Product;
use App\Scraping\ScraperFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

it('scrape:product dispatches a ScrapeProductJob for the URL', function () {
    Queue::fake();

    $this->artisan('scrape:product', ['url' => 'https://www.jumia.com.eg/apple-iphone-134276913.html'])
        ->assertSuccessful();

    Queue::assertPushed(ScrapeProductJob::class, fn (ScrapeProductJob $job) => $job->url === 'https://www.jumia.com.eg/apple-iphone-134276913.html' && $job->live === false);
});

it('scrape:product --sync runs inline and does not queue', function () {
    Queue::fake();
    config()->set('scraping.mode', 'fixture');

    $dir = config('scraping.fixtures_path');
    $url = json_decode(file_get_contents($dir.'/manifest.json'), true)[0]['source_url'];

    $this->artisan('scrape:product', ['url' => $url, '--sync' => true])->assertSuccessful();

    Queue::assertNothingPushed();
    expect(Product::where('source_url', $url)->exists())->toBeTrue();
});

it('scrape:product --live dispatches a job flagged live', function () {
    Queue::fake();

    $this->artisan('scrape:product', ['url' => 'https://www.jumia.com.eg/x-1.html', '--live' => true])
        ->assertSuccessful();

    Queue::assertPushed(ScrapeProductJob::class, fn (ScrapeProductJob $job) => $job->live === true);
});

it('scrape:run dispatches one job per manifest entry in fixture mode', function () {
    Queue::fake();
    config()->set('scraping.mode', 'fixture');

    $this->artisan('scrape:run')->assertSuccessful();

    $dir = config('scraping.fixtures_path');
    $expected = count(json_decode(file_get_contents($dir.'/manifest.json'), true));

    Queue::assertPushed(ScrapeProductJob::class, $expected);
});

it('scrape:run --live dispatches one live-flagged job per configured target, ignoring fixture mode', function () {
    Queue::fake();
    config()->set('scraping.mode', 'fixture'); // --live must override this

    $this->artisan('scrape:run', ['--live' => true])->assertSuccessful();

    $targets = config('scraping.targets');
    Queue::assertPushed(ScrapeProductJob::class, count($targets));
    Queue::assertPushed(ScrapeProductJob::class, fn (ScrapeProductJob $job) => $job->live === true && in_array($job->url, $targets, true));
});

it('scrape:run --sync seeds one row per manifest entry and is idempotent (boot seeding)', function () {
    Http::preventStrayRequests(); // fixture mode must touch no network
    config()->set('scraping.mode', 'fixture');

    $dir = config('scraping.fixtures_path');
    $expected = count(json_decode(file_get_contents($dir.'/manifest.json'), true));

    $this->artisan('scrape:run', ['--sync' => true])->assertSuccessful();
    expect(Product::count())->toBe($expected);

    $this->artisan('scrape:run', ['--sync' => true])->assertSuccessful();
    expect(Product::count())->toBe($expected); // second run updates in place, no duplicates
});

it('a job dispatched with live: true fetches over the network even when the worker is in fixture mode', function () {
    // The mode-leak case: config here is 'fixture', but the job carries live: true.
    Sleep::fake();
    config()->set('scraping.mode', 'fixture');
    config()->set('scraping.proxy_service_url', 'http://proxy:8080');
    config()->set('scraping.respect_robots', false);

    Http::fake([
        'proxy:8080/lease' => Http::response(['lease_id' => 'L1', 'proxy_url' => null, 'user_agent' => 'UA'], 200),
        'proxy:8080/report' => Http::response('', 204),
        'www.jumia.com.eg/*' => Http::response(fixtureHtml(), 200),
    ]);

    (new ScrapeProductJob('https://www.jumia.com.eg/apple-iphone-17-pro-max-6.9-256gb-rom-ios-26-5g-cosmic-orange-134276913.html', live: true))
        ->handle(app(ScraperFactory::class));

    // LiveFetcher was used — a FixtureFetcher would have made zero requests.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'www.jumia.com.eg/apple-'));
    Http::assertSent(fn ($request) => $request->url() === 'http://proxy:8080/lease');
});

it('a job dispatched with live: false uses the fixture transport in fixture mode', function () {
    Http::preventStrayRequests();
    config()->set('scraping.mode', 'fixture');

    (new ScrapeProductJob(fixtureUrl(), live: false))->handle(app(ScraperFactory::class));

    Http::assertNothingSent();
    expect(Product::where('source_url', fixtureUrl())->exists())->toBeTrue();
});
