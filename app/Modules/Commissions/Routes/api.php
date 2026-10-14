<?php

use App\Modules\Commissions\Controllers\CommissionController;
use App\Modules\Commissions\Controllers\CommissionRemittanceController;
use App\Modules\Commissions\Controllers\CommissionSettingsController;
use App\Modules\Commissions\Controllers\CommissionTierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Commission Management module API routes
|--------------------------------------------------------------------------
|
| Included from routes/api.php, so the "api" middleware group
| (throttle:api, SubstituteBindings, force.json) applies. Authorization is
| enforced per action through the CommissionTierPolicy ("view commissions"
| to read, "manage commissions" to change) and, for the ledger, the
| "view commissions" / "settle commissions" gates backed by CommissionPolicy.
|
*/

// The commission rules (fallback rate, when unpaid commission blocks a
// provider), edited on the Commission page rather than System Settings.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/commission-settings', [CommissionSettingsController::class, 'show']);
    Route::put('/commission-settings', [CommissionSettingsController::class, 'update']);
});

Route::prefix('commission-tiers')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [CommissionTierController::class, 'index']);
    Route::post('/', [CommissionTierController::class, 'store']);
    Route::get('/presets', [CommissionTierController::class, 'presets']);
    Route::post('/presets/{preset}/apply', [CommissionTierController::class, 'applyPreset'])->where('preset', '[a-z0-9_]+');
    Route::get('/{commissionTier}', [CommissionTierController::class, 'show'])->whereNumber('commissionTier');
    Route::put('/{commissionTier}', [CommissionTierController::class, 'update'])->whereNumber('commissionTier');
    Route::patch('/{commissionTier}', [CommissionTierController::class, 'update'])->whereNumber('commissionTier');
    Route::delete('/{commissionTier}', [CommissionTierController::class, 'destroy'])->whereNumber('commissionTier');
});

/* The ledger: what each booking earned SkillServe, and whether the provider
   has remitted it. Keyed by booking, because that is what the commission was
   charged for. */
Route::prefix('commissions')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [CommissionController::class, 'index']);
    Route::patch('/{booking}/settle', [CommissionController::class, 'settle'])->whereNumber('booking');
    Route::patch('/{booking}/waive', [CommissionController::class, 'waive'])->whereNumber('booking');
});

/* GCash payments providers submit for their outstanding commission, waiting
   for an administrator to find the reference in SkillServe's GCash history. */
Route::prefix('commission-remittances')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [CommissionRemittanceController::class, 'index']);
    Route::get('/{commissionRemittance}/proof', [CommissionRemittanceController::class, 'proof'])->whereNumber('commissionRemittance');
    Route::patch('/{commissionRemittance}/confirm', [CommissionRemittanceController::class, 'confirm'])->whereNumber('commissionRemittance');
    Route::patch('/{commissionRemittance}/reject', [CommissionRemittanceController::class, 'reject'])->whereNumber('commissionRemittance');
});
