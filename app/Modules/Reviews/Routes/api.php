<?php

use App\Modules\Reviews\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reviews and Ratings Management module API routes
|--------------------------------------------------------------------------
|
| Included from routes/api.php, so the "api" middleware group applies.
| Authorization is enforced per action through the ReviewPolicy.
|
*/

Route::prefix('reviews')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [ReviewController::class, 'index']);
    Route::get('/{review}', [ReviewController::class, 'show']);
    Route::patch('/{review}/hide', [ReviewController::class, 'hide']);
    Route::delete('/{review}', [ReviewController::class, 'destroy']);
});
