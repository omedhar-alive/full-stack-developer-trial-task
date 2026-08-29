<?php

use App\Models\Product;
use App\Scraping\ExtractorResolver;
use App\Scraping\Extractors\JumiaExtractor;
use App\Scraping\ProductScraper;
use Illuminate\Http\Client\RequestException;
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

// fixtureHtml() / fixtureUrl() live in tests/Pest.php — shared with ScrapeCommandTest.

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

/** Live-mode fake set with the target returning $status. */
function blockedFakes(int $status): array
{
    return [
        'www.jumia.com.eg/robots.txt' => Http::response("User-agent: *\nDisallow: /nope\n", 200),
        'proxy:8080/lease' => Http::response(['lease_id' => 'LX', 'proxy_url' => null, 'user_agent' => 'UA'], 200),
        'proxy:8080/report' => Http::response('', 204),
        'www.jumia.com.eg/apple-*' => Http::response('blocked', $status),
    ];
}

it('reports a 403 from the target to Go with its real status and lets the exception propagate', function () {
    config()->set('scraping.mode', 'live');
    Http::fake(blockedFakes(403));

    try {
        app(ProductScraper::class)->scrape(fixtureUrl());
        $this->fail('expected RequestException');
    } catch (RequestException $e) {
        expect($e->response->status())->toBe(403);
    }

    expect(Product::count())->toBe(0);
    Http::assertSent(fn ($r) => $r->url() === 'http://proxy:8080/report'
        && $r['lease_id'] === 'LX'
        && $r['ok'] === false
        && $r['status_code'] === 403);
});

it('does not retry a 403 — the target is requested exactly once', function () {
    config()->set('scraping.mode', 'live');
    Http::fake(blockedFakes(403));

    try {
        app(ProductScraper::class)->scrape(fixtureUrl());
    } catch (RequestException) {
        // expected
    }

    $targetHits = Http::recorded(fn ($request) => str_contains($request->url(), '/apple-'))->count();
    expect($targetHits)->toBe(1);
});

it('retries a 500 to the configured limit (3 attempts) and reports status 500', function () {
    config()->set('scraping.mode', 'live');
    config()->set('scraping.max_retries', 2); // 1 initial + 2 retries
    Http::fake(blockedFakes(500));

    try {
        app(ProductScraper::class)->scrape(fixtureUrl());
        $this->fail('expected RequestException');
    } catch (RequestException $e) {
        expect($e->response->status())->toBe(500);
    }

    expect(Http::recorded(fn ($request) => str_contains($request->url(), '/apple-'))->count())->toBe(3);
    Http::assertSent(fn ($r) => $r->url() === 'http://proxy:8080/report'
        && $r['ok'] === false
        && $r['status_code'] === 500);
});
