<?php

use App\Modules\Bookings\Controllers\BookingController;
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
