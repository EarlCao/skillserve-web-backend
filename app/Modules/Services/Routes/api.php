<?php

use App\Modules\Services\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Service Management module API routes
|--------------------------------------------------------------------------
|
| Included from routes/api.php, so the "api" middleware group
| (throttle:api, SubstituteBindings, force.json) applies. Authorization is
| enforced per action through the ServicePolicy.
|
*/

Route::prefix('services')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [ServiceController::class, 'index']);
    Route::post('/', [ServiceController::class, 'store']);
    Route::get('/{service}', [ServiceController::class, 'show']);
    Route::put('/{service}', [ServiceController::class, 'update']);
    Route::patch('/{service}', [ServiceController::class, 'update']);
    Route::patch('/{service}/approve', [ServiceController::class, 'approve']);
    Route::patch('/{service}/reject', [ServiceController::class, 'reject']);
    Route::patch('/{service}/hide', [ServiceController::class, 'hide']);
    Route::patch('/{service}/feature', [ServiceController::class, 'feature']);
    Route::delete('/{service}', [ServiceController::class, 'destroy']);
});
