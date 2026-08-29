<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

// Feature tests get the full Laravel TestCase (needs base_path(), config, etc)
// and a fresh in-memory database — LazilyRefreshDatabase only migrates for the
// tests that actually touch it, so the pure extractor tests stay fast.
// Unit tests run on the plain PHPUnit case — the scraping value objects are
// framework-free.
pest()->extend(TestCase::class)->use(LazilyRefreshDatabase::class)->in('Feature');

/**
 * The one manifest fixture: its real served HTML (used as a fake target
 * response in live-mode tests) and its real source URL.
 */
function fixtureHtml(): string
{
    $dir = config('scraping.fixtures_path');
    $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true);

    return file_get_contents($dir.'/'.$manifest[0]['file']);
}

function fixtureUrl(): string
{
    $dir = config('scraping.fixtures_path');

    return json_decode(file_get_contents($dir.'/manifest.json'), true)[0]['source_url'];
}
