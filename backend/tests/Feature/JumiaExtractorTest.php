<?php

use App\Exceptions\ExtractionFailedException;
use App\Scraping\Extractors\JumiaExtractor;
use App\Scraping\ProductData;
use Symfony\Component\DomCrawler\Crawler;

const JUMIA_FIXTURE_URL = 'https://www.jumia.com.eg/apple-iphone-17-pro-max-6.9-256gb-rom-ios-26-5g-cosmic-orange-134276913.html';

it('extracts every ProductData field from the saved Jumia product page', function () {
    $html = file_get_contents(base_path('tests/Fixtures/jumia.html'));

    $crawler = new Crawler;
    $crawler->addHtmlContent($html, 'UTF-8');

    $product = (new JumiaExtractor)->extract($crawler, JUMIA_FIXTURE_URL);

    expect($product)->toBeInstanceOf(ProductData::class)
        ->and($product->title)->toBe('iPhone 17 Pro Max 6.9" 256GB ROM iOS 26 5G - Cosmic Orange')
        ->and($product->priceMinor)->toBe(9277700)
        ->and($product->currency)->toBe('EGP')
        ->and($product->imageUrl)->toBe('https://eg.jumia.is/unsafe/fit-in/680x680/filters:fill(white)/product/31/9672431/1.jpg?7647')
        ->and($product->sourceUrl)->toBe(JUMIA_FIXTURE_URL);
});

it('throws ExtractionFailedException naming the URL and selector when the JSON-LD Product block is gone', function () {
    // Not a faked product page — a page with the structured data simply absent,
    // which is what a breaking markup change looks like.
    $crawler = new Crawler('<!doctype html><html><head><title>x</title></head><body><h1>No data here</h1></body></html>');

    try {
        (new JumiaExtractor)->extract($crawler, JUMIA_FIXTURE_URL);
        $this->fail('expected ExtractionFailedException');
    } catch (ExtractionFailedException $e) {
        expect($e->getMessage())
            ->toContain(JUMIA_FIXTURE_URL)
            ->toContain('application/ld+json');
    }
});

it('returns null (not an exception, not a row) for an out-of-stock Jumia page', function () {
    // Jumia removes out-of-stock products from its catalogue and 302s dead
    // product URLs to the homepage, so a real out-of-stock page could not be
    // fetched for a fixture. Per the phase 3 brief, skipping rather than
    // hand-writing markup. The null path itself lives in
    // JumiaExtractor::isOutOfStock() and the missing-price branch.
})->skip('no real out-of-stock Jumia page could be obtained');
