<?php

use App\Exceptions\PriceParseException;
use App\Scraping\PriceParser;

it('converts price text to minor units', function (string $raw, int $expected) {
    expect(PriceParser::toMinorUnits($raw))->toBe($expected);
})->with([
    'comma thousands separator' => ['12,999', 1299900],
    'currency code in the string' => ['EGP 12,999.00', 1299900],
    'currency symbol in the string' => ['$1,299.99', 129999],
    'a decimal price' => ['1299.50', 129950],
    'an integer price' => ['12999', 1299900],
    'rounds the third decimal up' => ['10.005', 1001],
    'rounds the third decimal down' => ['10.004', 1000],
    'NBSP + code, Jumia style' => ["EGP\u{00A0}92,777.00", 9277700],
]);

it('throws on text that is not a price', function (string $raw) {
    PriceParser::toMinorUnits($raw);
})->with([
    'empty string' => [''],
    'whitespace only' => ['   '],
    'not applicable' => ['N/A'],
    'a stray label' => ['Price on request'],
    'just dashes' => ['--'],
    'currency code only' => ['EGP'],
])->throws(PriceParseException::class);
