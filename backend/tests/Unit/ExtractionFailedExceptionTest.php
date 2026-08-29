<?php

use App\Exceptions\ExtractionFailedException;
use App\Exceptions\PriceParseException;

it('names the source URL and the selector when data is missing', function () {
    $e = ExtractionFailedException::missing('https://www.jumia.com.eg/p-123.html', 'Product.name');

    expect($e->getMessage())
        ->toContain('https://www.jumia.com.eg/p-123.html')
        ->toContain('Product.name');
});

it('wraps the underlying parse failure and keeps it as previous', function () {
    $cause = new PriceParseException('Price on request', 'no recognisable number');

    $e = ExtractionFailedException::unparsable(
        'https://www.jumia.com.eg/p-123.html',
        'Product.offers.price',
        $cause,
    );

    expect($e->getPrevious())->toBe($cause)
        ->and($e->getMessage())
        ->toContain('https://www.jumia.com.eg/p-123.html')
        ->toContain('Product.offers.price')
        ->toContain('Price on request'); // the original message survives
});
