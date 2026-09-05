<?php

use App\Modules\Audit\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::prefix('audit-logs')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [AuditLogController::class, 'index']);
    Route::get('/administrators', [AuditLogController::class, 'administrators']);
});
