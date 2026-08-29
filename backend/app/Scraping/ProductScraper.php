<?php

namespace App\Scraping;

use App\Models\Product;
use App\Scraping\Contracts\Fetcher;
use App\Scraping\Contracts\SiteExtractor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Orchestrates one scrape end to end.
 *
 * Live order: resolve extractor by host → robots check → lease → fetch →
 * report → extract → persist.
 *
 * Every transport outcome is reported to Go — success, a failed response with
 * its real status, or a connection failure as status 0. Go, not Laravel,
 * decides which of those is proxy-attributable (CONTRACTS.md §3). The ONLY
 * outcome that goes unreported is an extraction/parse failure: the markup
 * breaking is not the proxy's fault, and reporting it would bench a healthy
 * identity for a site problem.
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
        $startedAt = hrtime(true);

        try {
            $result = $this->fetcher->fetch($url, $lease);
        } catch (ConnectionException $e) {
            // No response at all — status 0 per CONTRACTS §3.
            $this->proxy->report($lease, null, false, $this->elapsedMs($startedAt));
            throw $e;
        } catch (RequestException $e) {
            // A response arrived and it was a failure (retries exhausted).
            // Report the REAL status — Go decides whether it is
            // proxy-attributable (403/407/408/429/502/503/504), not Laravel.
            $this->proxy->report($lease, $e->response->status(), false, $this->elapsedMs($startedAt));
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

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
