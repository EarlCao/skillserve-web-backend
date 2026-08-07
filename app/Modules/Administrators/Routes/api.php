<?php

use App\Modules\Administrators\Controllers\AdministratorController;
use App\Modules\Administrators\Controllers\PermissionController;
use App\Modules\Administrators\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Administrator Management module API routes
|--------------------------------------------------------------------------
|
| Included from routes/api.php, so the "api" middleware group
| (throttle:api, SubstituteBindings, force.json) applies. Authorization is
| enforced per action through the module policies (Authorization::authorize).
|
*/

Route::prefix('administrators')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [AdministratorController::class, 'index']);
    Route::post('/', [AdministratorController::class, 'store']);
    Route::get('/{administrator}', [AdministratorController::class, 'show']);
    Route::put('/{administrator}', [AdministratorController::class, 'update']);
    Route::patch('/{administrator}', [AdministratorController::class, 'update']);
    Route::patch('/{administrator}/status', [AdministratorController::class, 'updateStatus']);
    Route::patch('/{administrator}/password', [AdministratorController::class, 'resetPassword']);
});

Route::prefix('roles')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [RoleController::class, 'index']);
    Route::post('/', [RoleController::class, 'store']);
    Route::get('/{role}', [RoleController::class, 'show']);
    Route::put('/{role}', [RoleController::class, 'update']);
    Route::patch('/{role}', [RoleController::class, 'update']);
    Route::delete('/{role}', [RoleController::class, 'destroy']);
    Route::put('/{role}/permissions', [RoleController::class, 'syncPermissions']);
});

Route::prefix('permissions')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [PermissionController::class, 'index']);
});
