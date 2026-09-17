<?php

use App\Modules\ClientMarketplace\Controllers\ClientBookingController;
use App\Modules\ClientMarketplace\Controllers\ClientCatalogController;
use App\Modules\ClientMarketplace\Controllers\ClientReviewController;
use App\Modules\ClientMarketplace\Controllers\ProviderProfileController;
use App\Modules\ClientMarketplace\Controllers\ProviderServiceController;
use App\Modules\ClientMarketplace\Middleware\EnsureClient;
use App\Modules\ClientMarketplace\Middleware\EnsureProvider;
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

/* The signed-in provider's own profile (verification status and stats). */
Route::get('/provider/profile', [ProviderProfileController::class, 'show'])
    ->middleware(['auth:sanctum', EnsureProvider::class]);

/* Providers manage their own services; changes await administrator approval. */
Route::prefix('provider/services')->middleware(['auth:sanctum', EnsureProvider::class])->group(function (): void {
    Route::get('/', [ProviderServiceController::class, 'index']);
    Route::post('/', [ProviderServiceController::class, 'store']);
    Route::get('/{service}', [ProviderServiceController::class, 'show']);
    Route::put('/{service}', [ProviderServiceController::class, 'update']);
    Route::patch('/{service}', [ProviderServiceController::class, 'update']);
    Route::delete('/{service}', [ProviderServiceController::class, 'destroy']);
});
