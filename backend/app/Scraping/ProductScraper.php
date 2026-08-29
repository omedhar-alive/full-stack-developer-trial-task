<?php

namespace App\Scraping;

use App\Models\Product;
use App\Scraping\Contracts\Fetcher;
use App\Scraping\Contracts\SiteExtractor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Orchestrates one scrape end to end.
 *
 * Live order: resolve extractor by host → robots check → lease → fetch →
 * report → extract → persist. The report goes out BEFORE extraction runs: a
 * transport success is a transport success even if the markup then fails to
 * parse, and reporting a parse failure back to Go would bench a healthy proxy
 * for a site-markup problem.
 *
 * Fixture mode swaps only the transport — no robots check, no lease, no report.
 *
 * Returns the persisted Product, or null when the scrape legitimately produced
 * nothing (skipped by robots.txt, or the product is out of stock). Extraction
 * and parse failures are left to propagate so they land in failed_jobs.
 */
final class ProductScraper
{
    public function __construct(
        private readonly ExtractorResolver $resolver,
        private readonly Fetcher $fetcher,
        private readonly ProxyClient $proxy,
        private readonly RobotsChecker $robots,
        private readonly string $mode,
    ) {}

    public function scrape(string $url): ?Product
    {
        $extractor = $this->resolver->forUrl($url); // throws UnsupportedHostException

        if ($this->mode === 'fixture') {
            return $this->persist($extractor, $this->fetcher->fetch($url), $url);
        }

        if (! $this->robots->allows($url)) {
            Log::info('scrape skipped: disallowed by robots.txt', ['url' => $url]);

            return null;
        }

        $lease = $this->proxy->lease();

        try {
            $result = $this->fetcher->fetch($url, $lease);
        } catch (ConnectionException $e) {
            // No response — report a proxy-attributable failure, then let the
            // scrape fail into failed_jobs.
            $this->proxy->report($lease, null, false, 0);
            throw $e;
        }

        $this->proxy->report($lease, $result->statusCode, $result->ok(), $result->latencyMs);

        return $this->persist($extractor, $result, $url);
    }

    private function persist(SiteExtractor $extractor, FetchResult $result, string $url): ?Product
    {
        $crawler = new Crawler;
        $crawler->addHtmlContent($result->html, 'UTF-8');

        $data = $extractor->extract($crawler, $url); // null = out of stock; else throws on failure

        if ($data === null) {
            Log::info('scrape skipped: product out of stock', ['url' => $url]);

            return null;
        }

        return Product::updateOrCreate(
            ['source_url' => $data->sourceUrl],
            [
                'title' => $data->title,
                'price_minor' => $data->priceMinor,
                'currency' => $data->currency,
                'image_url' => $data->imageUrl,
            ],
        );
    }
}
