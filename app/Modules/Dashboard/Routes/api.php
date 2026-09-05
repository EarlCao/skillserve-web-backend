<?php

use App\Modules\Dashboard\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('dashboard')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index']);
});
