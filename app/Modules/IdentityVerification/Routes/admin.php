<?php

use App\Modules\IdentityVerification\Controllers\IdentityVerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity Verification — admin review queue
|--------------------------------------------------------------------------
|
| Included from routes/api.php, so the "api" middleware group
| (throttle:api, SubstituteBindings, force.json) applies. Authorization is
| enforced per action through IdentityVerificationPolicy: reading, approving
| and rejecting are three separate permissions.
|
*/

Route::prefix('identity-verifications')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [IdentityVerificationController::class, 'index']);
    Route::get('/{identityVerification}', [IdentityVerificationController::class, 'show'])
        ->whereNumber('identityVerification');
    Route::patch('/{identityVerification}/approve', [IdentityVerificationController::class, 'approve'])
        ->whereNumber('identityVerification');
    Route::patch('/{identityVerification}/reject', [IdentityVerificationController::class, 'reject'])
        ->whereNumber('identityVerification');
    Route::get('/{identityVerification}/documents/{document}/download', [IdentityVerificationController::class, 'downloadDocument'])
        ->whereNumber('identityVerification')->whereNumber('document');
});
