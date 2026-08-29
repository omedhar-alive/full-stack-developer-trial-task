<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

// Feature tests get the full Laravel TestCase (needs base_path(), config, etc)
// and a fresh in-memory database — LazilyRefreshDatabase only migrates for the
// tests that actually touch it, so the pure extractor tests stay fast.
// Unit tests run on the plain PHPUnit case — the scraping value objects are
// framework-free.
pest()->extend(TestCase::class)->use(LazilyRefreshDatabase::class)->in('Feature');
