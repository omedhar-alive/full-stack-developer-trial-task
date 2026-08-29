<?php

namespace App\Scraping\Support;

/**
 * Minimal URL resolution for scraped image references: absolute, protocol-
 * relative ("//cdn…"), and root-relative ("/img…") against the page's own URL.
 * Not a full RFC 3986 resolver — those three cases cover product-page images.
 */
final class Url
{
    public static function resolve(string $ref, string $base): string
    {
        $ref = trim($ref);
        if ($ref === '' || preg_match('#^https?://#i', $ref) === 1) {
            return $ref;
        }

        $parts = parse_url($base);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $ref;
        }

        if (str_starts_with($ref, '//')) {
            return $parts['scheme'].':'.$ref;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];
        if (str_starts_with($ref, '/')) {
            return $origin.$ref;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.$dir.$ref;
    }
}
