<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Modules\ClientAuthentication\Notifications\ClientEmailOtpNotification;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Six-digit email OTP verification for mobile sign-ups.
 *
 * Codes are hashed at rest, expire after a configurable window, allow
 * limited verification attempts, and are rate-limited per email via the
 * application cache.
 *
 * Two subjects carry a code:
 *  - a {@see PendingRegistration}, the normal path — the account does not
 *    exist yet and is created only once the code is confirmed;
 *  - a {@see User}, for accounts registered before deferred sign-up
 *    existed (and for re-verifying an address on an existing account).
 */
class ClientEmailOtpService
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Issue a fresh OTP to an existing account, replacing any previous one. */
    public function issue(User $user): void
    {
        $code = $this->generateCode($user->email);

        $user->forceFill($this->codeAttributes($code))->save();

        $user->notify(new ClientEmailOtpNotification($code, self::CODE_TTL_MINUTES));

        $this->startCooldown($user->email);
    }

    /** Issue a fresh OTP for an in-flight registration. */
    public function issueForRegistration(PendingRegistration $registration): void
    {
        $code = $this->generateCode($registration->email);

        $registration->forceFill($this->codeAttributes($code))->save();

        $registration->notify(new ClientEmailOtpNotification($code, self::CODE_TTL_MINUTES));

        $this->startCooldown($registration->email);
    }

    /** Verify a submitted code for an existing account; marks it verified. */
    public function verify(User $user, string $code): User
    {
        $this->assertCodeUsable($user->email_otp_hash, $user->email_otp_expires_at, (int) $user->email_otp_attempts);

        if (! Hash::check($code, (string) $user->email_otp_hash)) {
            $user->forceFill(['email_otp_attempts' => $user->email_otp_attempts + 1])->save();

            throw $this->incorrectCodeException((int) $user->email_otp_attempts);
        }

        $user->forceFill($this->clearedCodeAttributes())->save();

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $user->fresh();
    }

    /** Verify a submitted code for an in-flight registration. */
    public function verifyRegistration(PendingRegistration $registration, string $code): PendingRegistration
    {
        if ($registration->expires_at->isPast()) {
            throw new ApiException('This registration has expired. Please sign up again.', 422);
        }

        $this->assertCodeUsable(
            $registration->email_otp_hash,
            $registration->email_otp_expires_at,
            $registration->email_otp_attempts,
        );

        if (! Hash::check($code, (string) $registration->email_otp_hash)) {
            $registration->forceFill(['email_otp_attempts' => $registration->email_otp_attempts + 1])->save();

            throw $this->incorrectCodeException($registration->email_otp_attempts);
        }

        return $registration;
    }

    /** Whether a code was sent to this address inside the resend window. */
    public function isCoolingDown(string $email): bool
    {
        return Cache::has($this->cooldownKey($email));
    }

    /** Drop the per-email resend cooldown (e.g. after a cancelled sign-up). */
    public function clearCooldown(string $email): void
    {
        Cache::forget($this->cooldownKey($email));
    }

    /**
     * Reject a request made inside the resend window and return a new code.
     * The cooldown itself starts only once the mail has gone out, so a
     * failed send never locks the user out of retrying.
     */
    private function generateCode(string $email): string
    {
        if ($this->isCoolingDown($email)) {
            throw new ApiException('Please wait before requesting another code.', 429);
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function startCooldown(string $email): void
    {
        Cache::put($this->cooldownKey($email), true, now()->addSeconds(self::RESEND_COOLDOWN_SECONDS));
    }

    /** @return array<string, mixed> */
    private function codeAttributes(string $code): array
    {
        return [
            'email_otp_hash' => Hash::make($code),
            'email_otp_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'email_otp_attempts' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function clearedCodeAttributes(): array
    {
        return [
            'email_otp_hash' => null,
            'email_otp_expires_at' => null,
            'email_otp_attempts' => 0,
        ];
    }

    private function assertCodeUsable(?string $hash, ?Carbon $expiresAt, int $attempts): void
    {
        if (empty($hash) || $expiresAt?->isPast()) {
            throw new ApiException('This verification code has expired. Please request a new one.', 422);
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new ApiException('Too many incorrect attempts. Please request a new code.', 429);
        }
    }

    private function incorrectCodeException(int $attemptsUsed): ApiException
    {
        $remaining = self::MAX_ATTEMPTS - $attemptsUsed;

        return new ApiException(
            $remaining > 0
                ? "Incorrect verification code. {$remaining} attempts remaining."
                : 'Too many incorrect attempts. Please request a new code.',
            422,
        );
    }

    private function cooldownKey(string $email): string
    {
        return "email-otp:cooldown:{$email}";
    }
}
