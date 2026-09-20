<?php

use App\Modules\ClientCommunication\Controllers\BookingMessageController;
use App\Modules\ClientCommunication\Controllers\ClientNotificationController;
use App\Modules\ClientCommunication\Controllers\ClientSupportTicketController;
use App\Modules\ClientCommunication\Controllers\ConversationController;
use App\Modules\ClientMarketplace\Middleware\EnsureClient;
use App\Modules\ClientMarketplace\Middleware\EnsureMobileAccount;
use Illuminate\Support\Facades\Route;

// Customers and providers both receive notifications.
Route::prefix('notifications')->middleware(['auth:sanctum', EnsureMobileAccount::class])->group(function (): void {
    Route::get('/', [ClientNotificationController::class, 'index']);
    Route::get('/unread-count', [ClientNotificationController::class, 'unreadCount']);
    Route::patch('/{notification}/read', [ClientNotificationController::class, 'markRead']);
    Route::post('/read-all', [ClientNotificationController::class, 'readAll']);
});

Route::middleware(['auth:sanctum', EnsureClient::class])->group(function (): void {
    Route::prefix('support/tickets')->group(function (): void {
        Route::get('/', [ClientSupportTicketController::class, 'index']);
        Route::post('/', [ClientSupportTicketController::class, 'store']);
        Route::get('/{ticket}', [ClientSupportTicketController::class, 'show']);
        Route::post('/{ticket}/replies', [ClientSupportTicketController::class, 'reply']);
    });
});

// The Messages inbox. Customers and providers both have conversations, so the
// per-thread routes below authorize the participant rather than the role.
Route::prefix('conversations')->middleware(['auth:sanctum', EnsureMobileAccount::class])->group(function (): void {
    Route::get('/', [ConversationController::class, 'index']);
    Route::get('/unread-count', [ConversationController::class, 'unreadCount']);
});

Route::prefix('bookings/{booking}/messages')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [BookingMessageController::class, 'index']);
    Route::post('/', [BookingMessageController::class, 'store']);
});
