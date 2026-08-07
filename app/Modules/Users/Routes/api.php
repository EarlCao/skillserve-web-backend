<?php

use App\Modules\Users\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| User Management module API routes
|--------------------------------------------------------------------------
|
| Included from routes/api.php, so the "api" middleware group
| (throttle:api, SubstituteBindings, force.json) applies. Authorization is
| enforced per action through the "manage users" gate (see
| UserManagementPolicy / AppServiceProvider).
|
*/

Route::prefix('users')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [UserController::class, 'index']);
    Route::get('/{user}', [UserController::class, 'show']);
    Route::put('/{user}', [UserController::class, 'update']);
    Route::patch('/{user}', [UserController::class, 'update']);
    Route::patch('/{user}/suspend', [UserController::class, 'suspend']);
    Route::patch('/{user}/activate', [UserController::class, 'activate']);
    Route::patch('/{user}/ban', [UserController::class, 'ban']);
    Route::patch('/{user}/unban', [UserController::class, 'unban']);
    Route::get('/{user}/moderation-history', [UserController::class, 'moderationHistory']);
    Route::delete('/{user}', [UserController::class, 'destroy']);
});
