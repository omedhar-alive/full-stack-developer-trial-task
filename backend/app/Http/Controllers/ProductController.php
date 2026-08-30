<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProductResource::collection(
            Product::query()
                // Deciding what comes *out of the database* is a query concern;
                // deciding what goes *out to the client* is the Resource's. At
                // 20 rows the byte saving is nothing — the separation is the point.
                ->select(['id', 'title', 'price_minor', 'currency', 'image_url', 'source_url', 'created_at'])
                // timestamps() is second-precision, so a batch scrape ties every
                // row inside one second. id is monotonic and unique, so it settles
                // every tie — without it a row can appear on two pages, or none,
                // under LIMIT/OFFSET.
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                // 20 is a literal, deliberately not read from the request. A
                // client-controlled page size is a denial-of-service vector on a
                // table that grows with every scrape.
                ->paginate(20)
        );
    }
}
