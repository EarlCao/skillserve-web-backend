<?php

use App\Modules\Settings\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [SettingsController::class, 'index']);
    Route::put('/', [SettingsController::class, 'update']);
});
