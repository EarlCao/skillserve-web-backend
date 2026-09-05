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

Route::group([], base_path('app/Modules/Dashboard/Routes/api.php'));

Route::group([], base_path('app/Modules/Administrators/Routes/api.php'));

Route::group([], base_path('app/Modules/Users/Routes/api.php'));

Route::group([], base_path('app/Modules/ServiceCategories/Routes/api.php'));

Route::group([], base_path('app/Modules/Providers/Routes/api.php'));

Route::group([], base_path('app/Modules/Services/Routes/api.php'));

Route::group([], base_path('app/Modules/Bookings/Routes/api.php'));

Route::group([], base_path('app/Modules/Reviews/Routes/api.php'));

Route::group([], base_path('app/Modules/ReportsAndModeration/Routes/api.php'));

Route::group([], base_path('app/Modules/Notifications/Routes/api.php'));

Route::group([], base_path('app/Modules/Analytics/Routes/api.php'));

Route::group([], base_path('app/Modules/ProviderRecognition/Routes/api.php'));

Route::group([], base_path('app/Modules/Audit/Routes/api.php'));
