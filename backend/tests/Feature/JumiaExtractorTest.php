<?php

use App\Exceptions\ExtractionFailedException;
use App\Scraping\Extractors\JumiaExtractor;
use App\Scraping\ProductData;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Loads the Jumia entry from the fixture manifest — no hardcoded filename, so
 * added fixtures are picked up automatically.
 *
 * @return array{html: string, url: string}
 */
function jumiaFixture(): array
{
    $dir = config('scraping.fixtures_path');
    $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true);

    $entry = collect($manifest)->firstWhere(
        fn (array $e) => str_contains(parse_url($e['source_url'], PHP_URL_HOST) ?? '', 'jumia.')
    );

    expect($entry)->not->toBeNull();

    return [
        'html' => file_get_contents($dir.'/'.$entry['file']),
        'url' => $entry['source_url'],
    ];
}

it('supports the real Jumia host and its subdomains but rejects lookalikes', function () {
    $extractor = new JumiaExtractor;

    expect($extractor->supports('jumia.com.eg'))->toBeTrue()
        ->and($extractor->supports('www.jumia.com.eg'))->toBeTrue()
        ->and($extractor->supports('WWW.JUMIA.COM.EG'))->toBeTrue()
        ->and($extractor->supports('notjumia.com'))->toBeFalse()
        ->and($extractor->supports('jumia.com.eg.evil.com'))->toBeFalse();
});

it('extracts every ProductData field from the saved Jumia product page', function () {
    ['html' => $html, 'url' => $url] = jumiaFixture();

    $crawler = new Crawler;
    $crawler->addHtmlContent($html, 'UTF-8');

    $product = (new JumiaExtractor)->extract($crawler, $url);

    expect($product)->toBeInstanceOf(ProductData::class)
        ->and($product->title)->toBe('iPhone 17 Pro Max 6.9" 256GB ROM iOS 26 5G - Cosmic Orange')
        ->and($product->priceMinor)->toBe(9277700)
        ->and($product->currency)->toBe('EGP')
        ->and($product->imageUrl)->toBe('https://eg.jumia.is/unsafe/fit-in/680x680/filters:fill(white)/product/31/9672431/1.jpg?7647')
        ->and($product->sourceUrl)->toBe($url);
});

it('throws ExtractionFailedException naming the URL and selector when the JSON-LD Product block is gone', function () {
    $url = jumiaFixture()['url'];
    $crawler = new Crawler('<!doctype html><html><head><title>x</title></head><body><h1>No data here</h1></body></html>');

    try {
        (new JumiaExtractor)->extract($crawler, $url);
        $this->fail('expected ExtractionFailedException');
    } catch (ExtractionFailedException $e) {
        expect($e->getMessage())
            ->toContain($url)
            ->toContain('application/ld+json');
    }
});

it('throws (does not return null) when an in-stock offer has no price', function () {
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'A Product That Is In Stock',
        'image' => 'https://eg.jumia.is/x.jpg',
        'offers' => [
            '@type' => 'Offer',
            'availability' => 'https://schema.org/InStock',
            'priceCurrency' => 'EGP',
            // no "price"
        ],
    ]);
    $crawler = new Crawler(
        '<!doctype html><html><body><script type="application/ld+json">'.$json.'</script></body></html>'
    );

    try {
        (new JumiaExtractor)->extract($crawler, jumiaFixture()['url']);
        $this->fail('expected ExtractionFailedException');
    } catch (ExtractionFailedException $e) {
        expect($e->getMessage())
            ->toContain(jumiaFixture()['url'])
            ->toContain('Product.offers.price');
    }
});

it('returns null (not an exception, not a row) for an out-of-stock Jumia page', function () {
    // Jumia removes out-of-stock products from its catalogue and 302s dead
    // product URLs to the homepage, so a real out-of-stock page could not be
    // fetched for a fixture. Per the phase 3 brief, skipping rather than
    // hand-writing markup. The null path now lives solely in
    // JumiaExtractor::isOutOfStock().
})->skip('no real out-of-stock Jumia page could be obtained');
