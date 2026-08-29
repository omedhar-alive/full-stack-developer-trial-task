<?php

use App\Models\Product;
use App\Scraping\ExtractorResolver;
use App\Scraping\Extractors\JumiaExtractor;
use App\Scraping\ProductScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    config()->set('scraping.proxy_service_url', 'http://proxy:8080');
    config()->set('scraping.throttle_ms', 2000);
    config()->set('scraping.respect_robots', true);
});

/** The real served Jumia HTML, used as a fake target response in live-mode tests. */
function fixtureHtml(): string
{
    $dir = config('scraping.fixtures_path');
    $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true);

    return file_get_contents($dir.'/'.$manifest[0]['file']);
}

function fixtureUrl(): string
{
    $dir = config('scraping.fixtures_path');

    return json_decode(file_get_contents($dir.'/manifest.json'), true)[0]['source_url'];
}

// ─────────────────────────────────────────────────────────────────────────────

it('completes the scrape and writes a product even when the Go proxy service is unreachable', function () {
    // THE HEADLINE TEST — the one the voice note refers to.
    Log::spy();
    config()->set('scraping.mode', 'live');

    Http::fake([
        'proxy:8080/lease' => Http::failedConnection(),   // Go service down
        'proxy:8080/report' => Http::failedConnection(),  // and it stays down
        'www.jumia.com.eg/*' => Http::response(fixtureHtml(), 200),
    ]);

    $product = app(ProductScraper::class)->scrape(fixtureUrl());

    expect($product)->toBeInstanceOf(Product::class)
        ->and(Product::where('source_url', fixtureUrl())->count())->toBe(1)
        ->and($product->title)->toBe('iPhone 17 Pro Max 6.9" 256GB ROM iOS 26 5G - Cosmic Orange');

    // Falling back to the local user-agent list is a logged warning, not a failure.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m) => str_contains($m, 'proxy lease unavailable'))
        ->once();
});

it('skips a URL disallowed by robots.txt without taking a lease or writing a row', function () {
    Log::spy();
    config()->set('scraping.mode', 'live');

    Http::fake([
        'www.jumia.com.eg/robots.txt' => Http::response("User-agent: *\nDisallow: /\n", 200),
        'proxy:8080/*' => Http::failedConnection(), // must never be reached
        'www.jumia.com.eg/apple-*' => Http::response(fixtureHtml(), 200),
    ]);

    $result = app(ProductScraper::class)->scrape(fixtureUrl());

    expect($result)->toBeNull()
        ->and(Product::count())->toBe(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/lease'));
});

it('proceeds with the scrape when robots.txt is unreachable, logging a warning', function () {
    Log::spy();
    config()->set('scraping.mode', 'live');

    Http::fake([
        'www.jumia.com.eg/robots.txt' => Http::failedConnection(),
        'proxy:8080/lease' => Http::response(['lease_id' => 'L1', 'proxy_url' => null, 'user_agent' => 'UA'], 200),
        'proxy:8080/report' => Http::response('', 204),
        'www.jumia.com.eg/apple-*' => Http::response(fixtureHtml(), 200),
    ]);

    $product = app(ProductScraper::class)->scrape(fixtureUrl());

    expect($product)->toBeInstanceOf(Product::class);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m) => str_contains($m, 'robots.txt'))
        ->once();
});

it('reports the transport outcome to Go before extraction runs', function () {
    config()->set('scraping.mode', 'live');

    Http::fake([
        'www.jumia.com.eg/robots.txt' => Http::response("User-agent: *\nDisallow: /nowhere\n", 200),
        'proxy:8080/lease' => Http::response(['lease_id' => 'L9', 'proxy_url' => null, 'user_agent' => 'UA'], 200),
        'proxy:8080/report' => Http::response('', 204),
        'www.jumia.com.eg/apple-*' => Http::response(fixtureHtml(), 200),
    ]);

    app(ProductScraper::class)->scrape(fixtureUrl());

    Http::assertSent(fn ($request) => $request->url() === 'http://proxy:8080/report'
        && $request['lease_id'] === 'L9'
        && $request['ok'] === true
        && $request['status_code'] === 200);
});

it('resolves a working ExtractorResolver through the container (variadic-constructor regression)', function () {
    $resolver = app(ExtractorResolver::class);

    expect($resolver)->toBeInstanceOf(ExtractorResolver::class)
        ->and($resolver->forUrl('https://www.jumia.com.eg/apple-iphone-134276913.html'))
        ->toBeInstanceOf(JumiaExtractor::class);
});

it('writes one row and updates it when the same URL is scraped twice', function () {
    config()->set('scraping.mode', 'fixture');

    $first = app(ProductScraper::class)->scrape(fixtureUrl());
    $first->update(['price_minor' => 1]); // pretend the price moved

    $second = app(ProductScraper::class)->scrape(fixtureUrl());

    expect(Product::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->fresh()->price_minor)->toBe(9277700); // re-scrape corrected it
});

it('runs fixture mode end to end from the manifest with no network touched', function () {
    // preventStrayRequests() from beforeEach turns any HTTP call into a failure.
    config()->set('scraping.mode', 'fixture');

    $product = app(ProductScraper::class)->scrape(fixtureUrl());

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product->currency)->toBe('EGP')
        ->and($product->source_url)->toBe(fixtureUrl());
    Http::assertNothingSent();
});
