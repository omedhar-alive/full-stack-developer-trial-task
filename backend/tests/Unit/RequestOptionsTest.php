<?php

use App\Scraping\Lease;
use App\Scraping\RequestOptions;

it('omits the proxy key for a direct lease', function () {
    $options = RequestOptions::for(
        new Lease(leaseId: 'l1', proxyUrl: null, userAgent: 'UA/1.0', fromFallback: false),
        connectTimeout: 15,
        readTimeout: 20,
        maxRedirects: 3,
    );

    expect($options)->not->toHaveKey('proxy')
        ->and($options['connect_timeout'])->toBe(15)
        ->and($options['timeout'])->toBe(20)
        ->and($options['allow_redirects'])->toBe(['max' => 3])
        ->and($options['headers']['User-Agent'])->toBe('UA/1.0');
});

it('omits the proxy key for an empty-string proxy url', function () {
    $options = RequestOptions::for(
        new Lease('l1', '', 'UA/1.0', false),
        15, 15, 3,
    );

    expect($options)->not->toHaveKey('proxy');
});

it('includes the proxy key only when the proxy url is a non-empty string', function () {
    $options = RequestOptions::for(
        new Lease('l1', 'http://proxy.example:8000', 'UA/1.0', false),
        15, 15, 5,
    );

    expect($options['proxy'])->toBe('http://proxy.example:8000')
        ->and($options['allow_redirects'])->toBe(['max' => 5]);
});
