<?php

use App\Modules\Support\Controllers\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('support/tickets')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [SupportTicketController::class, 'index']);
    Route::get('/assignees', [SupportTicketController::class, 'assignees']);
    Route::get('/{ticket}', [SupportTicketController::class, 'show']);
    Route::patch('/{ticket}/assign', [SupportTicketController::class, 'assign']);
    Route::post('/{ticket}/responses', [SupportTicketController::class, 'respond']);
    Route::patch('/{ticket}/resolve', [SupportTicketController::class, 'resolve']);
});
