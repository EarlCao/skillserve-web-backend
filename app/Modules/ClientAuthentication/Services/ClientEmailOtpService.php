<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\ClientAuthentication\Notifications\ClientEmailOtpNotification;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Six-digit email OTP verification for mobile accounts (customers and
 * mobile-registered providers). Codes are hashed at rest, expire after a
 * configurable window, allow limited verification attempts, and are
 * rate-limited per email via the application cache.
 */
class ClientEmailOtpService
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Issue a fresh OTP, replacing any previous one. */
    public function issue(User $user): void
    {
        $cooldownKey = "email-otp:cooldown:{$user->email}";

        if (Cache::has($cooldownKey)) {
            throw new ApiException(
                'Please wait before requesting another code.',
                429,
            );
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'email_otp_hash' => Hash::make($code),
            'email_otp_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'email_otp_attempts' => 0,
        ])->save();

        $user->notify(new ClientEmailOtpNotification($code, self::CODE_TTL_MINUTES));

        Cache::put($cooldownKey, true, now()->addSeconds(self::RESEND_COOLDOWN_SECONDS));
    }

    /** Verify a submitted code; on success, mark the email verified. */
    public function verify(User $user, string $code): User
    {
        if (empty($user->email_otp_hash) || $user->email_otp_expires_at?->isPast()) {
            throw new ApiException('This verification code has expired. Please request a new one.', 422);
        }

        if ($user->email_otp_attempts >= self::MAX_ATTEMPTS) {
            throw new ApiException('Too many incorrect attempts. Please request a new code.', 429);
        }

        if (! Hash::check($code, $user->email_otp_hash)) {
            $user->forceFill(['email_otp_attempts' => $user->email_otp_attempts + 1])->save();

            $remaining = self::MAX_ATTEMPTS - ($user->email_otp_attempts + 1);
            throw new ApiException(
                $remaining > 0
                    ? "Incorrect verification code. {$remaining} attempts remaining."
                    : 'Too many incorrect attempts. Please request a new code.',
                422,
            );
        }

        $user->forceFill([
            'email_otp_hash' => null,
            'email_otp_expires_at' => null,
            'email_otp_attempts' => 0,
        ])->save();

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $user->fresh();
    }
}
