<?php

use App\Modules\Providers\Controllers\ProviderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Provider Management module API routes
|--------------------------------------------------------------------------
|
| Included from routes/api.php, so the "api" middleware group
| (throttle:api, SubstituteBindings, force.json) applies. Authorization is
| enforced per action through the "manage providers" gate (see
| ProviderPolicy / AppServiceProvider).
|
*/

Route::prefix('providers')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [ProviderController::class, 'index']);
    Route::get('/{provider}', [ProviderController::class, 'show']);

    // Verification workflow
    Route::patch('/{provider}/verification/approve', [ProviderController::class, 'approveVerification']);
    Route::patch('/{provider}/verification/reject', [ProviderController::class, 'rejectVerification']);
    Route::patch('/{provider}/verification/request-info', [ProviderController::class, 'requestAdditionalInfo']);
    Route::patch('/{provider}/verification/remove', [ProviderController::class, 'removeVerification']);
    Route::get('/{provider}/verification-history', [ProviderController::class, 'verificationHistory']);
    Route::get('/{provider}/verification-documents/{document}/download', [ProviderController::class, 'downloadVerificationDocument']);

    // Provider status management
    Route::patch('/{provider}/suspend', [ProviderController::class, 'suspend']);
    Route::patch('/{provider}/activate', [ProviderController::class, 'activate']);
});
