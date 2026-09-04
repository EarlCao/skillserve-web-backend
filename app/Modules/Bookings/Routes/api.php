<?php

use App\Modules\Bookings\Controllers\BookingController;
use App\Modules\Bookings\Controllers\DisputeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Booking Management module API routes
|--------------------------------------------------------------------------
|
| Included from routes/api.php, so the "api" middleware group
| (throttle:api, SubstituteBindings, force.json) applies. Authorization is
| enforced per action through the BookingPolicy.
|
*/

Route::prefix('bookings')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [BookingController::class, 'index']);
    Route::get('/{booking}', [BookingController::class, 'show']);
    Route::get('/{booking}/history', [BookingController::class, 'history']);
    Route::patch('/{booking}/cancel', [BookingController::class, 'cancel']);
    Route::patch('/{booking}/dispute', [BookingController::class, 'dispute']);
});

Route::prefix('disputes')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [DisputeController::class, 'index']);
    Route::get('/{booking}', [DisputeController::class, 'show']);
    Route::get('/{booking}/history', [DisputeController::class, 'history']);
    Route::patch('/{booking}/investigate', [DisputeController::class, 'investigate']);
    Route::patch('/{booking}/notes', [DisputeController::class, 'addNote']);
    Route::patch('/{booking}/resolve', [DisputeController::class, 'resolve']);
    Route::patch('/{booking}/reject', [DisputeController::class, 'reject']);
    Route::patch('/{booking}/close', [DisputeController::class, 'close']);
});
