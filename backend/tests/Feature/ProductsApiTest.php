<?php

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

// The throttle counter lives in the cache store; flush it so this suite's
// rate-limit test is not sensitive to how the cache is configured in a later
// phase, nor to requests made by the tests above it.
beforeEach(fn () => Cache::flush());

it('returns exactly the seven contract fields and the pagination envelope', function () {
    Product::factory()->create([
        'title' => 'Infinix Hot 40i 256GB',
        'price_minor' => 12999, // -> price 129.99 exactly
        'currency' => 'EGP',
        'image_url' => 'https://eg.jumia.is/unsafe/fit-in/680x680/product/1/1.jpg',
        'source_url' => 'https://www.jumia.com.eg/infinix-hot-40i-123456789.html',
    ]);

    $response = $this->getJson('/api/products');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'title', 'price', 'currency', 'image_url', 'source_url', 'created_at']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ])
        ->assertJsonPath('data.0.price', 129.99)
        ->assertJsonPath('data.0.currency', 'EGP')
        ->assertJsonPath('data.0.title', 'Infinix Hot 40i 256GB')
        ->assertJsonMissingPath('data.0.price_minor')
        ->assertJsonMissingPath('data.0.updated_at');

    // The seven-field list is exhaustive.
    expect(array_keys($response->json('data.0')))
        ->toBe(['id', 'title', 'price', 'currency', 'image_url', 'source_url', 'created_at']);
});

it('paginates 20 per page', function () {
    Product::factory()->count(25)->create();

    $page1 = $this->getJson('/api/products');
    $page1->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 20);
    expect($page1->json('links.next'))->not->toBeNull();

    $page2 = $this->getJson('/api/products?page=2');
    $page2->assertOk()->assertJsonCount(5, 'data');
    expect($page2->json('links.next'))->toBeNull();
});

it('breaks created_at ties by descending id', function () {
    // Same explicit created_at on all three — Eloquent keeps a timestamp that
    // was set explicitly. This test fails if orderByDesc('id') is dropped.
    $at = now()->subDay();
    $created = Product::factory()->count(3)->create(['created_at' => $at]);

    $ids = $this->getJson('/api/products')->json('data.*.id');

    expect($ids)->toHaveCount(3)
        ->and($ids)->toBe($created->pluck('id')->sortDesc()->values()->all())
        ->and($ids)->toBe(collect($ids)->sortDesc()->values()->all()); // strictly descending
});

it('returns 200 with an empty data array for an empty table (not a 404)', function () {
    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0);
});

it('treats a non-integer or negative page as page 1', function () {
    Product::factory()->count(3)->create();

    foreach (['abc', '-1'] as $page) {
        $this->getJson("/api/products?page={$page}")
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonCount(3, 'data');
    }
});

it('returns 200 with empty data for a page past the end', function () {
    Product::factory()->count(25)->create();

    $this->getJson('/api/products?page=999')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.current_page', 999);
});

it('rate limits at 60 requests per minute with a JSON 429', function () {
    Product::factory()->count(2)->create();

    $responses = [];
    for ($i = 0; $i < 61; $i++) {
        $responses[$i] = $this->getJson('/api/products');
    }

    for ($i = 0; $i < 60; $i++) {
        expect($responses[$i]->status())->toBe(200);
    }

    expect($responses[60]->status())->toBe(429)
        ->and($responses[60]->json())->toBeArray()      // parseable JSON body
        ->and($responses[60]->json('message'))->not->toBeNull();
});
