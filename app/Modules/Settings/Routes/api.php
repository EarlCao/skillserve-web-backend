<?php

use App\Modules\Settings\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Public: the admin web's theme, applied on the sign-in page too. Nothing in
// it is secret.
Route::get('/appearance', [SettingsController::class, 'appearance']);

Route::prefix('settings')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [SettingsController::class, 'index']);
    Route::put('/', [SettingsController::class, 'update']);
});
