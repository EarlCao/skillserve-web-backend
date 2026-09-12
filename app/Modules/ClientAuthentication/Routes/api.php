<?php

use App\Modules\ClientAuthentication\Controllers\ClientAuthController;
use App\Modules\ClientAuthentication\Middleware\EnsureActiveClient;
use Illuminate\Support\Facades\Route;

/*
 * Mounted by the client marketplace service provider under /api/client/v1/auth.
 */

Route::post('/register', [ClientAuthController::class, 'register']);
Route::post('/cancel-registration', [ClientAuthController::class, 'cancelRegistration']);
Route::post('/register-provider', [ClientAuthController::class, 'registerProvider']);
Route::post('/verify-otp', [ClientAuthController::class, 'verifyOtp']);
Route::post('/resend-otp', [ClientAuthController::class, 'resendOtp']);
Route::post('/google', [ClientAuthController::class, 'google']);
Route::post('/login', [ClientAuthController::class, 'login'])->middleware('throttle:login');
Route::post('/refresh', [ClientAuthController::class, 'refresh']);
Route::post('/forgot-password', [ClientAuthController::class, 'forgotPassword']);
Route::post('/reset-password', [ClientAuthController::class, 'resetPassword']);
Route::get('/verify-email/{user}/{hash}', [ClientAuthController::class, 'verifyEmail'])
    ->middleware('signed')
    ->name('client.verification.verify');

Route::middleware(['auth:sanctum', EnsureActiveClient::class])->group(function (): void {
    Route::get('/me', [ClientAuthController::class, 'me']);
    Route::post('/logout', [ClientAuthController::class, 'logout']);
    Route::post('/change-password', [ClientAuthController::class, 'changePassword']);
    Route::post('/verification-notification', [ClientAuthController::class, 'sendVerificationNotification']);
});
