<?php

use App\Scraping\ProductData;

function validProductData(array $overrides = []): array
{
    return array_merge([
        'title' => 'Apple iPhone 17 Pro Max',
        'priceMinor' => 9277700,
        'currency' => 'EGP',
        'imageUrl' => 'https://eg.jumia.is/product/31/9672431/1.jpg',
        'sourceUrl' => 'https://www.jumia.com.eg/some-product-134276913.html',
    ], $overrides);
}

it('accepts a well-formed row and exposes it unchanged', function () {
    $data = new ProductData(...validProductData());

    expect($data->title)->toBe('Apple iPhone 17 Pro Max')
        ->and($data->priceMinor)->toBe(9277700)
        ->and($data->currency)->toBe('EGP')
        ->and($data->imageUrl)->toBe('https://eg.jumia.is/product/31/9672431/1.jpg')
        ->and($data->sourceUrl)->toBe('https://www.jumia.com.eg/some-product-134276913.html');
});

it('rejects an empty title', function () {
    new ProductData(...validProductData(['title' => '   ']));
})->throws(InvalidArgumentException::class);

it('rejects a negative price', function () {
    new ProductData(...validProductData(['priceMinor' => -1]));
})->throws(InvalidArgumentException::class);

it('rejects a currency that is not a 3-letter ISO code', function () {
    new ProductData(...validProductData(['currency' => 'Egp']));
})->throws(InvalidArgumentException::class);

it('rejects a blank image URL', function () {
    new ProductData(...validProductData(['imageUrl' => '']));
})->throws(InvalidArgumentException::class);

it('rejects a source URL that is not a URL', function () {
    new ProductData(...validProductData(['sourceUrl' => 'not-a-url']));
})->throws(InvalidArgumentException::class);
