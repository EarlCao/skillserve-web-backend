<?php

use App\Modules\ProviderRecognition\Controllers\ProviderRecognitionController;
use Illuminate\Support\Facades\Route;

Route::prefix('provider-recognition')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/badges', [ProviderRecognitionController::class, 'badges']);
    Route::post('/badges', [ProviderRecognitionController::class, 'storeBadge']);
    Route::put('/badges/{badge}', [ProviderRecognitionController::class, 'updateBadge']);
    Route::delete('/badges/{badge}', [ProviderRecognitionController::class, 'destroyBadge']);
    Route::get('/providers', [ProviderRecognitionController::class, 'providers']);
    Route::get('/top-rated', [ProviderRecognitionController::class, 'topRated']);
    Route::post('/providers/{provider}/badges', [ProviderRecognitionController::class, 'assignBadge']);
    Route::delete('/providers/{provider}/badges/{badge}', [ProviderRecognitionController::class, 'removeBadge']);
    Route::patch('/providers/{provider}/featured', [ProviderRecognitionController::class, 'featured']);
});
