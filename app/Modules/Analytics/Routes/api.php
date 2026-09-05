<?php

use App\Modules\Analytics\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reports and Analytics module API routes
|--------------------------------------------------------------------------
|
| Included from routes/api.php, so the "api" middleware group applies.
| Authorization is enforced per action through the AnalyticsPolicy gates.
|
*/

Route::prefix('analytics')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/export', [ReportController::class, 'export']);
});
