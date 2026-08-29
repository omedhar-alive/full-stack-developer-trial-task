<?php

use App\Scraping\RobotsChecker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('disallows a path blocked for User-agent: * in robots.txt', function () {
    Http::fake([
        'jumia.com.eg/robots.txt' => Http::response("User-agent: *\nDisallow: /checkout\nDisallow: /account\n", 200),
    ]);

    $checker = new RobotsChecker(enabled: true);

    expect($checker->allows('https://www.jumia.com.eg/account/orders'))->toBeFalse()
        ->and($checker->allows('https://www.jumia.com.eg/apple-iphone-134276913.html'))->toBeTrue();
});

it('fails open and warns when robots.txt is unreachable', function () {
    Log::spy();
    Http::fake(['*/robots.txt' => Http::failedConnection()]);

    $allowed = (new RobotsChecker(enabled: true))->allows('https://www.jumia.com.eg/apple-iphone-134276913.html');

    expect($allowed)->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
});

it('fails open when robots.txt returns a non-200', function () {
    Log::spy();
    Http::fake(['*/robots.txt' => Http::response('nope', 500)]);

    expect((new RobotsChecker(enabled: true))->allows('https://www.jumia.com.eg/x'))->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
});

it('makes no request at all when respect_robots is off', function () {
    Http::fake(); // any call recorded

    expect((new RobotsChecker(enabled: false))->allows('https://www.jumia.com.eg/checkout'))->toBeTrue();
    Http::assertNothingSent();
});
