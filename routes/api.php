<?php

use Illuminate\Support\Facades\DB;
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

Route::get('/health', function () {
    $checks = [
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'services' => [],
    ];

    try {
        DB::connection()->getPdo();
        $checks['services']['database'] = ['status' => 'up'];
    } catch (Throwable $e) {
        $checks['services']['database'] = ['status' => 'down', 'error' => $e->getMessage()];
        $checks['status'] = 'degraded';
    }

    // Uploads are written under storage/app, which in production is a Render
    // persistent disk. A disk that is missing or read-only would lose every
    // upload, so it counts as degraded like the database.
    $uploads = storage_path('app');
    if (is_dir($uploads) && is_writable($uploads)) {
        $checks['services']['storage'] = ['status' => 'up'];
    } else {
        $checks['services']['storage'] = ['status' => 'down', 'error' => 'storage/app is not writable.'];
        $checks['status'] = 'degraded';
    }

    $status = $checks['status'] === 'ok' ? 200 : 503;

    return response()->json($checks, $status);
})->withoutMiddleware(['throttle:api']);

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

Route::group([], base_path('app/Modules/Commissions/Routes/api.php'));

Route::group([], base_path('app/Modules/IdentityVerification/Routes/admin.php'));

Route::group([], base_path('app/Modules/Payments/Routes/api.php'));

Route::group([], base_path('app/Modules/Reviews/Routes/api.php'));

Route::group([], base_path('app/Modules/ReportsAndModeration/Routes/api.php'));

Route::group([], base_path('app/Modules/Notifications/Routes/api.php'));

Route::group([], base_path('app/Modules/Analytics/Routes/api.php'));

Route::group([], base_path('app/Modules/ProviderRecognition/Routes/api.php'));

Route::group([], base_path('app/Modules/Audit/Routes/api.php'));

Route::group([], base_path('app/Modules/Settings/Routes/api.php'));

Route::group([], base_path('app/Modules/DataManagement/Routes/api.php'));

Route::group([], base_path('app/Modules/Support/Routes/api.php'));
