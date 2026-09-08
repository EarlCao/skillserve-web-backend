<?php

use App\Modules\DataManagement\Controllers\DataManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('data-management')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/archives', [DataManagementController::class, 'archives']);
    Route::post('/archives', [DataManagementController::class, 'archive']);
    Route::post('/archives/{archive}/restore', [DataManagementController::class, 'restoreArchive']);
    Route::get('/deleted', [DataManagementController::class, 'deleted']);
    Route::post('/deleted/{type}/{id}/restore', [DataManagementController::class, 'restoreDeleted']);
    Route::delete('/deleted/{type}/{id}', [DataManagementController::class, 'permanentlyDelete']);
    Route::get('/export', [DataManagementController::class, 'export']);
});
