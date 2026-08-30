<?php

use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

// Registered under the "api" prefix (see bootstrap/app.php), so this resolves
// to GET /api/health — the phase 1 health endpoint from CONTRACTS.md section 7.
Route::get('/health', fn () => response()->json(['status' => 'ok']));

// throttle is attached explicitly here, not inherited: Laravel 13's default
// `api` middleware group is only SubstituteBindings — `throttle:api` is added
// by throttleApi() in bootstrap/app.php, which this app does not call. It stays
// off /health on purpose: a container healthcheck polls on a fixed interval,
// and sharing a bucket with API traffic could 429 the healthcheck and make
// Docker restart a container that was never unhealthy.
Route::middleware('throttle:60,1')
    ->get('/products', [ProductController::class, 'index']);
