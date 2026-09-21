<?php

use App\Modules\ClientCommunication\Controllers\BookingMessageController;
use App\Modules\ClientCommunication\Controllers\ClientNotificationController;
use App\Modules\ClientCommunication\Controllers\ClientReportController;
use App\Modules\ClientCommunication\Controllers\ClientSupportTicketController;
use App\Modules\ClientCommunication\Controllers\ConversationController;
use App\Modules\ClientCommunication\Middleware\EnsureBackgroundNotificationToken;
use App\Modules\ClientMarketplace\Middleware\EnsureMobileAccount;
use Illuminate\Support\Facades\Route;

// Customers and providers both receive notifications.
Route::prefix('notifications')->middleware(['auth:sanctum', EnsureMobileAccount::class])->group(function (): void {
    Route::get('/', [ClientNotificationController::class, 'index']);
    Route::get('/unread-count', [ClientNotificationController::class, 'unreadCount']);
    Route::patch('/{notification}/read', [ClientNotificationController::class, 'markRead']);
    Route::post('/read-all', [ClientNotificationController::class, 'readAll']);
    Route::post('/background-token', [ClientNotificationController::class, 'backgroundToken']);
});

// The app's background task, while the app is closed: a narrow token that
// can read pending notifications and nothing else.
Route::get('/notifications/background', [ClientNotificationController::class, 'background'])
    ->middleware(['auth:sanctum', EnsureBackgroundNotificationToken::class]);

// Support is for everyone who uses the app: customers and providers both
// raise tickets, each seeing only their own.
Route::middleware(['auth:sanctum', EnsureMobileAccount::class])->group(function (): void {
    Route::prefix('support/tickets')->group(function (): void {
        Route::get('/', [ClientSupportTicketController::class, 'index']);
        Route::post('/', [ClientSupportTicketController::class, 'store']);
        Route::get('/{ticket}', [ClientSupportTicketController::class, 'show']);
        Route::post('/{ticket}/replies', [ClientSupportTicketController::class, 'reply']);
    });
});

// Complaints. Customers and providers can both report the other party on a
// booking, so this is not limited to the customer surface.
Route::prefix('reports')->middleware(['auth:sanctum', EnsureMobileAccount::class])->group(function (): void {
    Route::get('/', [ClientReportController::class, 'index']);
    Route::post('/', [ClientReportController::class, 'store']);
    Route::get('/{report}', [ClientReportController::class, 'show'])->whereNumber('report');
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
    Route::post('/read', [BookingMessageController::class, 'markRead']);
});
