<?php

use App\Modules\Notifications\Controllers\AdminInboxController;
use App\Modules\Notifications\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('notifications')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/recipients', [NotificationController::class, 'recipients']);
    Route::post('/announcements', [NotificationController::class, 'store']);
    Route::delete('/announcements/{announcement}', [NotificationController::class, 'destroy']);
});

// Each administrator's own alerts (the admin Inbox and its bell).
Route::prefix('inbox')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [AdminInboxController::class, 'index']);
    Route::get('/unread-count', [AdminInboxController::class, 'unreadCount']);
    Route::patch('/{alert}/read', [AdminInboxController::class, 'markRead'])->whereUuid('alert');
    Route::post('/read-all', [AdminInboxController::class, 'readAll']);
});
