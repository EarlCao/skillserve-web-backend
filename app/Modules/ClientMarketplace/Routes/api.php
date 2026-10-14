<?php

use App\Modules\ClientMarketplace\Controllers\BookingDisputeController;
use App\Modules\ClientMarketplace\Controllers\ClientBookingController;
use App\Modules\ClientMarketplace\Controllers\ClientCatalogController;
use App\Modules\ClientMarketplace\Controllers\ClientPaymentController;
use App\Modules\ClientMarketplace\Controllers\ClientReviewController;
use App\Modules\ClientMarketplace\Controllers\FavoriteProviderController;
use App\Modules\ClientMarketplace\Controllers\PaymentProofController;
use App\Modules\ClientMarketplace\Controllers\ProviderBookingController;
use App\Modules\ClientMarketplace\Controllers\ProviderCommissionController;
use App\Modules\ClientMarketplace\Controllers\ProviderProfileController;
use App\Modules\ClientMarketplace\Controllers\ProviderServiceController;
use App\Modules\ClientMarketplace\Controllers\ProviderStatisticsController;
use App\Modules\ClientMarketplace\Controllers\ProviderVerificationController;
use App\Modules\ClientMarketplace\Middleware\EnsureClient;
use App\Modules\ClientMarketplace\Middleware\EnsureMobileAccount;
use App\Modules\ClientMarketplace\Middleware\EnsureProvider;
use Illuminate\Support\Facades\Route;

/* Public catalog discovery. */
Route::get('/categories', [ClientCatalogController::class, 'categories']);
Route::get('/categories/{category}', [ClientCatalogController::class, 'category']);
Route::get('/services', [ClientCatalogController::class, 'services']);
Route::get('/services/{service}', [ClientCatalogController::class, 'service']);
Route::get('/services/{service}/reviews', [ClientCatalogController::class, 'serviceReviews'])->whereNumber('service');
Route::get('/providers', [ClientCatalogController::class, 'providers']);
Route::get('/providers/{provider}', [ClientCatalogController::class, 'provider']);

Route::middleware(['auth:sanctum', EnsureClient::class])->group(function (): void {
    Route::prefix('bookings')->group(function (): void {
        Route::get('/', [ClientBookingController::class, 'index']);
        Route::post('/', [ClientBookingController::class, 'store']);
        Route::get('/{booking}', [ClientBookingController::class, 'show']);
        Route::patch('/{booking}/cancel', [ClientBookingController::class, 'cancel']);
        Route::patch('/{booking}/reschedule', [ClientBookingController::class, 'reschedule']);

        // Starts an online payment. The booking is marked paid by the
        // gateway's webhook, never by the customer returning from it.
        Route::post('/{booking}/pay', [ClientPaymentController::class, 'store'])->whereNumber('booking');

        // Proof of a GCash payment to the provider (a downpayment or the rest).
        Route::post('/{booking}/payment-proofs', [PaymentProofController::class, 'store'])
            ->whereNumber('booking')->middleware('throttle:10,1');
        Route::get('/{booking}/payment-proofs/{proof}/image', [PaymentProofController::class, 'customerImage'])
            ->whereNumber(['booking', 'proof']);
    });

    Route::get('/favorites', [FavoriteProviderController::class, 'index']);
    Route::put('/favorites/{provider}', [FavoriteProviderController::class, 'store'])->whereNumber('provider');
    Route::delete('/favorites/{provider}', [FavoriteProviderController::class, 'destroy'])->whereNumber('provider');

    Route::prefix('reviews')->group(function (): void {
        Route::get('/', [ClientReviewController::class, 'index']);
        Route::post('/', [ClientReviewController::class, 'store']);
        Route::put('/{review}', [ClientReviewController::class, 'update']);
        Route::patch('/{review}', [ClientReviewController::class, 'update']);
    });
});

/* The signed-in provider's own account: profile, portfolio and badges. */
Route::middleware(['auth:sanctum', EnsureProvider::class])->group(function (): void {
    Route::get('/provider/profile', [ProviderProfileController::class, 'show']);
    Route::patch('/provider/profile', [ProviderProfileController::class, 'update']);

    Route::get('/provider/portfolio', [ProviderProfileController::class, 'portfolio']);
    Route::post('/provider/portfolio', [ProviderProfileController::class, 'storePortfolioItem']);
    Route::patch('/provider/portfolio/{item}', [ProviderProfileController::class, 'updatePortfolioItem'])
        ->whereNumber('item');
    Route::delete('/provider/portfolio/{item}', [ProviderProfileController::class, 'destroyPortfolioItem'])
        ->whereNumber('item');
    Route::post('/provider/portfolio/{item}/images', [ProviderProfileController::class, 'storePortfolioImages'])
        ->whereNumber('item');
    Route::delete('/provider/portfolio/{item}/images/{image}', [ProviderProfileController::class, 'destroyPortfolioImage'])
        ->whereNumber(['item', 'image']);

    Route::get('/provider/availability', [ProviderProfileController::class, 'availability']);
    Route::put('/provider/availability', [ProviderProfileController::class, 'updateAvailability']);

    Route::get('/provider/badges', [ProviderProfileController::class, 'badges']);

    // Performance computed live from the provider's bookings and reviews.
    Route::get('/provider/statistics', [ProviderStatisticsController::class, 'show']);

    Route::get('/provider/verification', [ProviderVerificationController::class, 'show']);
    Route::post('/provider/verification', [ProviderVerificationController::class, 'store']);

    // What the provider owes SkillServe, and whether it is blocking them.
    Route::get('/provider/commissions', [ProviderCommissionController::class, 'index']);

    // The provider's payments. Submitting a GCash reference is retired (410,
    // so an outdated app says to update); only the QR Ph code below is taken.
    Route::get('/provider/commission-remittances', [ProviderCommissionController::class, 'remittances']);
    Route::post('/provider/commission-remittances', [ProviderCommissionController::class, 'storeRemittance']);
    // A PayMongo QR Ph code for the exact amount, confirmed by its webhook.
    // Tapping twice returns the open code; the throttle stops a loop making
    // codes nobody pays.
    Route::post('/provider/commission-remittances/qr', [ProviderCommissionController::class, 'startQrPayment'])
        ->middleware('throttle:10,1');
    Route::get('/provider/commission-remittances/{commissionRemittance}', [ProviderCommissionController::class, 'showRemittance'])
        ->whereNumber('commissionRemittance');
});

/* Disputes belong to both parties on a booking, so they sit outside the
   customer-only and provider-only groups and authorize the participant. */
Route::middleware(['auth:sanctum', EnsureMobileAccount::class])->group(function (): void {
    Route::get('/disputes', [BookingDisputeController::class, 'index']);
    Route::patch('/bookings/{booking}/dispute', [BookingDisputeController::class, 'store'])
        ->whereNumber('booking');
    Route::post('/bookings/{booking}/dispute/evidence', [BookingDisputeController::class, 'storeEvidence'])
        ->whereNumber('booking');
});

/* The provider's jobs: the bookings placed with them and their lifecycle. */
Route::prefix('provider/bookings')->middleware(['auth:sanctum', EnsureProvider::class])->group(function (): void {
    Route::get('/', [ProviderBookingController::class, 'index']);
    Route::get('/{booking}', [ProviderBookingController::class, 'show'])->whereNumber('booking');
    Route::patch('/{booking}/confirm', [ProviderBookingController::class, 'confirm'])->whereNumber('booking');
    Route::patch('/{booking}/decline', [ProviderBookingController::class, 'decline'])->whereNumber('booking');
    Route::patch('/{booking}/cancel', [ProviderBookingController::class, 'cancel'])->whereNumber('booking');
    Route::patch('/{booking}/start', [ProviderBookingController::class, 'start'])->whereNumber('booking');
    Route::patch('/{booking}/complete', [ProviderBookingController::class, 'complete'])->whereNumber('booking');
    Route::patch('/{booking}/payment-received', [ProviderBookingController::class, 'paymentReceived'])->whereNumber('booking');
    Route::patch('/{booking}/payment-proofs/{proof}/confirm', [PaymentProofController::class, 'confirm'])->whereNumber(['booking', 'proof']);
    Route::patch('/{booking}/payment-proofs/{proof}/reject', [PaymentProofController::class, 'reject'])->whereNumber(['booking', 'proof']);
    Route::get('/{booking}/payment-proofs/{proof}/image', [PaymentProofController::class, 'providerImage'])->whereNumber(['booking', 'proof']);
});

/* What a price means for the provider, before they publish it. Outside the
   services prefix because it answers a question about an amount, not about an
   existing service. */
Route::get('/provider/commission-preview', [ProviderServiceController::class, 'commissionPreview'])
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
