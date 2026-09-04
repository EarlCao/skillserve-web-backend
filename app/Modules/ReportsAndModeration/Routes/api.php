<?php

use App\Modules\ReportsAndModeration\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reports and Moderation module API routes
|--------------------------------------------------------------------------
|
| Included from routes/api.php, so the "api" middleware group applies.
| Authorization is enforced per action through the ReportPolicy.
|
*/

Route::prefix('reports')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [ReportController::class, 'index']);
    Route::get('/{report}', [ReportController::class, 'show']);
    Route::patch('/{report}/investigate', [ReportController::class, 'investigate']);
    Route::patch('/{report}/notes', [ReportController::class, 'addNote']);
    Route::patch('/{report}/resolve', [ReportController::class, 'resolve']);
    Route::patch('/{report}/reject', [ReportController::class, 'reject']);
    Route::patch('/{report}/action', [ReportController::class, 'takeAction']);
});
