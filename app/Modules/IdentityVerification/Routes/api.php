<?php

use App\Modules\ClientMarketplace\Middleware\EnsureMobileAccount;
use App\Modules\IdentityVerification\Controllers\ClientIdentityVerificationController;
use App\Modules\IdentityVerification\Controllers\TransactionEligibilityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity Verification — mobile account surface
|--------------------------------------------------------------------------
|
| Mounted under api/client/v1 by ClientMarketplaceServiceProvider. Customers
| and providers share these endpoints, so the shared EnsureMobileAccount
| middleware applies rather than a role-specific one.
|
| Submission is rate limited separately from the rest of the API: it is the
| one endpoint where repeated calls could be used to probe whether a given
| National ID is already registered.
|
*/

Route::prefix('identity-verification')
    ->middleware(['auth:sanctum', EnsureMobileAccount::class])
    ->group(function (): void {
        Route::get('/', [ClientIdentityVerificationController::class, 'show']);
        Route::post('/', [ClientIdentityVerificationController::class, 'store'])
            ->middleware('throttle:identity-verification');
    });

/* Why an action would be refused, so the app can prompt correctly. Advisory
   only: every protected action re-checks the same rules server-side. */
Route::get('/transaction-eligibility', [TransactionEligibilityController::class, 'show'])
    ->middleware(['auth:sanctum', EnsureMobileAccount::class]);
