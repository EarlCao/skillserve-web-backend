<?php

use App\Modules\ClientMarketplace\Controllers\ClientBookingController;
use App\Modules\ClientMarketplace\Controllers\ClientCatalogController;
use App\Modules\ClientMarketplace\Controllers\ClientReviewController;
use App\Modules\ClientMarketplace\Middleware\EnsureClient;
use Illuminate\Support\Facades\Route;

/* Public catalog discovery. */
Route::get('/categories', [ClientCatalogController::class, 'categories']);
Route::get('/categories/{category}', [ClientCatalogController::class, 'category']);
Route::get('/services', [ClientCatalogController::class, 'services']);
Route::get('/services/{service}', [ClientCatalogController::class, 'service']);
Route::get('/providers', [ClientCatalogController::class, 'providers']);
Route::get('/providers/{provider}', [ClientCatalogController::class, 'provider']);

Route::middleware(['auth:sanctum', EnsureClient::class])->group(function (): void {
    Route::prefix('bookings')->group(function (): void {
        Route::get('/', [ClientBookingController::class, 'index']);
        Route::post('/', [ClientBookingController::class, 'store']);
        Route::get('/{booking}', [ClientBookingController::class, 'show']);
        Route::patch('/{booking}/cancel', [ClientBookingController::class, 'cancel']);
    });

    Route::prefix('reviews')->group(function (): void {
        Route::get('/', [ClientReviewController::class, 'index']);
        Route::post('/', [ClientReviewController::class, 'store']);
        Route::put('/{review}', [ClientReviewController::class, 'update']);
        Route::patch('/{review}', [ClientReviewController::class, 'update']);
    });
});
