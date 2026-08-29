<?php

namespace App\Scraping\Extractors;

use App\Exceptions\ExtractionFailedException;
use App\Exceptions\PriceParseException;
use App\Scraping\Contracts\SiteExtractor;
use App\Scraping\PriceParser;
use App\Scraping\ProductData;
use App\Scraping\Support\Url;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Jumia publishes a schema.org Product as JSON-LD on every product page. That
 * is the stable thing to read — Jumia's visible CSS classes are generated and
 * churn. The JSON-LD block is still located with a CSS selector via
 * dom-crawler; the HTML is never regex'd.
 */
final class JumiaExtractor implements SiteExtractor
{
    /** Substrings of schema.org availability values that mean "no sale right now". */
    private const OUT_OF_STOCK = ['outofstock', 'soldout', 'discontinued', 'backorder'];

    public function supports(string $host): bool
    {
        return str_contains(strtolower($host), 'jumia.');
    }

    public function extract(Crawler $html, string $sourceUrl): ?ProductData
    {
        $product = $this->jsonLdProduct($html);
        if ($product === null) {
            throw ExtractionFailedException::missing($sourceUrl, 'script[type="application/ld+json"] Product');
        }

        $offer = $this->firstOffer($product);

        // Legitimately unavailable — not an error. Caller logs at info and skips.
        if ($this->isOutOfStock($offer)) {
            return null;
        }

        $title = $this->str($product['name'] ?? null);
        if ($title === null) {
            throw ExtractionFailedException::missing($sourceUrl, 'Product.name');
        }

        $rawPrice = $this->str($offer['price'] ?? null);
        if ($rawPrice === null) {
            // In stock as far as we can tell, but no price on the page. Treat as
            // out of stock rather than inventing a number.
            return null;
        }

        $currency = $this->str($offer['priceCurrency'] ?? null);
        if ($currency === null) {
            throw ExtractionFailedException::missing($sourceUrl, 'Product.offers.priceCurrency');
        }

        $image = $this->firstImage($product);
        if ($image === null) {
            throw ExtractionFailedException::missing($sourceUrl, 'Product.image');
        }

        try {
            $priceMinor = PriceParser::toMinorUnits($rawPrice);
        } catch (PriceParseException $e) {
            throw ExtractionFailedException::unparsable($sourceUrl, 'Product.offers.price', $e);
        }

        return new ProductData(
            title: $title,
            priceMinor: $priceMinor,
            currency: strtoupper($currency),
            imageUrl: Url::resolve($image, $sourceUrl),
            sourceUrl: $sourceUrl,
        );
    }

    /** @return array<string, mixed>|null */
    private function jsonLdProduct(Crawler $html): ?array
    {
        foreach ($html->filter('script[type="application/ld+json"]') as $node) {
            $decoded = json_decode((string) $node->textContent, true);
            if (! is_array($decoded)) {
                continue;
            }

            $entries = isset($decoded['@graph']) && is_array($decoded['@graph'])
                ? $decoded['@graph']
                : [$decoded];

            foreach ($entries as $entry) {
                if (is_array($entry) && ($entry['@type'] ?? null) === 'Product') {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * offers may be a single Offer, a list of Offers, or an AggregateOffer that
     * wraps them. Return the first concrete offer.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function firstOffer(array $product): array
    {
        $offers = $product['offers'] ?? [];
        if (! is_array($offers)) {
            return [];
        }
        if (array_is_list($offers)) {
            return is_array($offers[0] ?? null) ? $offers[0] : [];
        }
        if (isset($offers['offers']) && is_array($offers['offers'])) {
            $inner = $offers['offers'];

            return array_is_list($inner) ? (is_array($inner[0] ?? null) ? $inner[0] : []) : $inner;
        }

        return $offers;
    }

    /** @param  array<string, mixed>  $offer */
    private function isOutOfStock(array $offer): bool
    {
        $availability = strtolower((string) ($offer['availability'] ?? ''));

        foreach (self::OUT_OF_STOCK as $needle) {
            if ($availability !== '' && str_contains($availability, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $product */
    private function firstImage(array $product): ?string
    {
        $image = $product['image'] ?? null;

        if (is_string($image)) {
            return $this->str($image);
        }

        if (is_array($image)) {
            // ["https://…", …]
            if (array_is_list($image)) {
                $first = $image[0] ?? null;

                return is_array($first)
                    ? $this->str($first['contentUrl'] ?? $first['url'] ?? null)
                    : $this->str($first);
            }

            // {"@type":"ImageObject","contentUrl":"https://…" | ["https://…", …]}
            $url = $image['contentUrl'] ?? $image['url'] ?? null;
            if (is_array($url)) {
                $url = $url[0] ?? null;
            }

            return $this->str($url);
        }

        return null;
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
