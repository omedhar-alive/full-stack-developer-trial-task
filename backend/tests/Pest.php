<?php

use Tests\TestCase;

// Feature tests get the full Laravel TestCase (needs base_path(), config, etc).
// Unit tests run on the plain PHPUnit case — the scraping code is framework-free.
pest()->extend(TestCase::class)->in('Feature');
