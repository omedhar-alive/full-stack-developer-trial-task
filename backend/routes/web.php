<?php

use Illuminate\Support\Facades\Route;

// This service has no HTML surface — it is a JSON API and scraper only.
// The old route returned view('welcome'), which called @vite() against a
// build manifest the Docker image never builds. `/` now points callers at
// the real endpoints instead.
Route::get('/', fn () => response()->json([
    'service' => 'backend',
    'endpoints' => [
        'health' => url('/api/health'),
        'products' => url('/api/products'),
    ],
]));
