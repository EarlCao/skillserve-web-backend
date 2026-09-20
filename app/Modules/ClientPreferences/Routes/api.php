<?php

use App\Modules\ClientAuthentication\Middleware\EnsureActiveClient;
use App\Modules\ClientPreferences\Controllers\ClientPreferenceController;
use Illuminate\Support\Facades\Route;

/*
 * Mounted by the client marketplace service provider under /api/client/v1.
 */

Route::middleware(['auth:sanctum', EnsureActiveClient::class])->group(function (): void {
    Route::get('/preferences', [ClientPreferenceController::class, 'show']);
    Route::put('/preferences', [ClientPreferenceController::class, 'update']);
});
