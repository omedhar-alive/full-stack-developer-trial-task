<?php

use App\Jobs\ScrapeProductJob;
use App\Models\Product;
use Illuminate\Support\Facades\Queue;

it('scrape:product dispatches a ScrapeProductJob for the URL', function () {
    Queue::fake();

    $this->artisan('scrape:product', ['url' => 'https://www.jumia.com.eg/apple-iphone-134276913.html'])
        ->assertSuccessful();

    Queue::assertPushed(ScrapeProductJob::class, fn (ScrapeProductJob $job) => $job->url === 'https://www.jumia.com.eg/apple-iphone-134276913.html');
});

it('scrape:product --sync runs inline and does not queue', function () {
    Queue::fake();
    config()->set('scraping.mode', 'fixture');

    $dir = config('scraping.fixtures_path');
    $url = json_decode(file_get_contents($dir.'/manifest.json'), true)[0]['source_url'];

    $this->artisan('scrape:product', ['url' => $url, '--sync' => true])->assertSuccessful();

    Queue::assertNothingPushed();
    expect(Product::where('source_url', $url)->exists())->toBeTrue();
});

it('scrape:run dispatches one job per manifest entry in fixture mode', function () {
    Queue::fake();
    config()->set('scraping.mode', 'fixture');

    $this->artisan('scrape:run')->assertSuccessful();

    $dir = config('scraping.fixtures_path');
    $expected = count(json_decode(file_get_contents($dir.'/manifest.json'), true));

    Queue::assertPushed(ScrapeProductJob::class, $expected);
});
