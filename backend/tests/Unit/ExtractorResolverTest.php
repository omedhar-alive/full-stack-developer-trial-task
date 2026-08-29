<?php

use App\Exceptions\UnsupportedHostException;
use App\Scraping\Contracts\SiteExtractor;
use App\Scraping\ExtractorResolver;
use App\Scraping\Extractors\JumiaExtractor;
use App\Scraping\ProductData;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A throwaway SiteExtractor bound to an arbitrary host, so the dispatch test
 * can prove the resolver skips non-matching extractors and returns the one
 * that matches — not just "returns the only extractor there is". Not a
 * shipped extractor.
 */
function fakeExtractorForHost(string $hostNeedle): SiteExtractor
{
    return new class($hostNeedle) implements SiteExtractor
    {
        public function __construct(private string $hostNeedle) {}

        public function supports(string $host): bool
        {
            return str_contains($host, $this->hostNeedle);
        }

        public function extract(Crawler $html, string $sourceUrl): ?ProductData
        {
            return null;
        }
    };
}

it('routes a URL to the extractor whose supports() matches its host', function () {
    $jumia = new JumiaExtractor;
    $other = fakeExtractorForHost('shop.example');
    $resolver = new ExtractorResolver($other, $jumia);

    expect($resolver->forUrl('https://www.jumia.com.eg/apple-iphone-134276913.html'))->toBe($jumia)
        ->and($resolver->forUrl('https://shop.example/item/1'))->toBe($other);
});

it('throws a typed exception for a host no extractor supports', function () {
    (new ExtractorResolver(new JumiaExtractor))->forUrl('https://www.example.org/item/123');
})->throws(UnsupportedHostException::class);

it('throws when the URL has no host at all', function () {
    (new ExtractorResolver(new JumiaExtractor))->forUrl('not a url');
})->throws(UnsupportedHostException::class);
