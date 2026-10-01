<?php

use App\Modules\Locations\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Philippine locations (PSGC) — mounted under /api/client/v1
|--------------------------------------------------------------------------
|
| Public: the address picker is needed during sign-up, before an account
| exists. Read-only reference data.
|
*/

Route::prefix('locations')->group(function (): void {
    Route::get('/regions', [LocationController::class, 'regions']);
    Route::get('/match', [LocationController::class, 'match']);
    Route::get('/{location}/children', [LocationController::class, 'children'])->where('location', '[0-9]{9}');
});
