<?php

use App\Scraping\Lease;
use App\Scraping\ProxyClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('scraping.proxy_service_url', 'http://proxy:8080');
    config()->set('scraping.fallback_user_agents', ['FB-UA-1', 'FB-UA-2']);
});

function proxyClient(): ProxyClient
{
    return new ProxyClient(
        config('scraping.proxy_service_url'),
        config('scraping.fallback_user_agents'),
    );
}

it('parses a 200 lease into a real, reportable Lease', function () {
    Http::fake([
        'proxy:8080/lease' => Http::response([
            'lease_id' => '9f1c-abcd',
            'proxy_url' => null,
            'user_agent' => 'Mozilla/5.0 (real)',
        ], 200),
    ]);

    $lease = proxyClient()->lease();

    expect($lease)->toBeInstanceOf(Lease::class)
        ->and($lease->leaseId)->toBe('9f1c-abcd')
        ->and($lease->proxyUrl)->toBeNull()
        ->and($lease->userAgent)->toBe('Mozilla/5.0 (real)')
        ->and($lease->fromFallback)->toBeFalse()
        ->and($lease->isReportable())->toBeTrue();
});

it('falls back on a 503 from /lease, logs a warning, does not throw', function () {
    Log::spy();
    Http::fake([
        'proxy:8080/lease' => Http::response(['error' => 'no_healthy_entries', 'retry_after_seconds' => 30], 503),
    ]);

    $lease = proxyClient()->lease();

    expect($lease->fromFallback)->toBeTrue()
        ->and($lease->leaseId)->toBeNull()
        ->and($lease->userAgent)->toBe('FB-UA-1');
    Log::shouldHaveReceived('warning')->once();
});

it('falls back on a connection failure from /lease, logs a warning, does not throw', function () {
    Log::spy();
    Http::fake(['proxy:8080/lease' => Http::failedConnection()]);

    $lease = proxyClient()->lease();

    expect($lease->fromFallback)->toBeTrue()
        ->and($lease->leaseId)->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

it('rotates the fallback user-agent across calls', function () {
    Log::spy();
    Http::fake(['proxy:8080/lease' => Http::failedConnection()]);
    $client = proxyClient();

    expect($client->lease()->userAgent)->toBe('FB-UA-1')
        ->and($client->lease()->userAgent)->toBe('FB-UA-2')
        ->and($client->lease()->userAgent)->toBe('FB-UA-1');
});

it('reports a no-response fetch as status_code 0', function () {
    Http::fake([
        'proxy:8080/report' => Http::response('', 204),
    ]);

    proxyClient()->report(
        new Lease('lease-xyz', null, 'UA', false),
        statusCode: null,
        ok: false,
        latencyMs: 0,
    );

    Http::assertSent(fn ($request) => $request->url() === 'http://proxy:8080/report'
        && $request['lease_id'] === 'lease-xyz'
        && $request['ok'] === false
        && $request['status_code'] === 0);
});

it('does not report at all for a fallback lease', function () {
    Http::fake(); // any request would be recorded

    proxyClient()->report(Lease::fallback('FB-UA-1'), 200, true, 12);

    Http::assertNothingSent();
});

it('never propagates a failing report', function () {
    Log::spy();
    Http::fake(['proxy:8080/report' => Http::failedConnection()]);

    // If report() rethrew, this line would never run and the test would error.
    proxyClient()->report(new Lease('lease-1', null, 'UA', false), 200, true, 5);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m) => str_contains($m, 'proxy report failed'))
        ->once();
});
