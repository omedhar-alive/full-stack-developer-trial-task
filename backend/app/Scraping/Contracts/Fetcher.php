<?php

namespace App\Scraping\Contracts;

use App\Scraping\FetchResult;
use App\Scraping\Lease;

interface Fetcher
{
    /**
     * Fetch one page.
     *
     * @param  ?Lease  $lease  the proxy/user-agent handout for this request.
     *                         Required by LiveFetcher (it builds the request
     *                         options from it); ignored by FixtureFetcher,
     *                         which makes no request and takes no lease.
     */
    public function fetch(string $url, ?Lease $lease = null): FetchResult;
}
