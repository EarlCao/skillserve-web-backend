<?php

use App\Modules\ClientCommunication\Controllers\BookingMessageController;
use App\Modules\ClientCommunication\Controllers\ClientNotificationController;
use App\Modules\ClientCommunication\Controllers\ClientSupportTicketController;
use App\Modules\ClientMarketplace\Middleware\EnsureClient;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', EnsureClient::class])->group(function (): void {
    Route::prefix('notifications')->group(function (): void {
        Route::get('/', [ClientNotificationController::class, 'index']);
        Route::get('/unread-count', [ClientNotificationController::class, 'unreadCount']);
        Route::patch('/{notification}/read', [ClientNotificationController::class, 'markRead']);
        Route::post('/read-all', [ClientNotificationController::class, 'readAll']);
    });

    Route::prefix('support/tickets')->group(function (): void {
        Route::get('/', [ClientSupportTicketController::class, 'index']);
        Route::post('/', [ClientSupportTicketController::class, 'store']);
        Route::get('/{ticket}', [ClientSupportTicketController::class, 'show']);
        Route::post('/{ticket}/replies', [ClientSupportTicketController::class, 'reply']);
    });
});

Route::prefix('bookings/{booking}/messages')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [BookingMessageController::class, 'index']);
    Route::post('/', [BookingMessageController::class, 'store']);
});
