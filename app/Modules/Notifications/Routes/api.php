<?php

use App\Modules\Notifications\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('notifications')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/recipients', [NotificationController::class, 'recipients']);
    Route::post('/announcements', [NotificationController::class, 'store']);
});
