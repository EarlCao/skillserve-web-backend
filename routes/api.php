<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Phase 0 — shared infrastructure only. Module routes are mounted here and
| inherit the "api" middleware group (throttle:api, SubstituteBindings,
| force.json).
|
*/

Route::prefix('auth')->group(
    base_path('app/Modules/Authentication/Routes/api.php'),
);

Route::group([], base_path('app/Modules/Administrators/Routes/api.php'));
