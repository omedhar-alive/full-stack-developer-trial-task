<?php

use Illuminate\Support\Facades\Route;

// Registered under the "api" prefix (see bootstrap/app.php), so this resolves
// to GET /api/health — the phase 1 health endpoint from CONTRACTS.md section 7.
Route::get('/health', fn () => response()->json(['status' => 'ok']));
