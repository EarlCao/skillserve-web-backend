<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Modules\ClientAuthentication\Notifications\ClientEmailOtpNotification;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\TwilioVerifyClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Six-digit email OTP verification for mobile sign-ups and password resets.
 *
 * Codes are hashed at rest, expire after a configurable window, allow
 * limited verification attempts, and are rate-limited per email via the
 * application cache.
 *
 * Delivery (OTP_DRIVER): `mail` generates the code here and emails it through
 * the configured mailer; `twilio` has Twilio Verify generate, email and check
 * it. Either way the expiry, the attempt limit and the resend cooldown are
 * enforced here, so the API behaves the same.
 *
 * Two subjects carry a code:
 *  - a {@see PendingRegistration}, the normal path — the account does not
 *    exist yet and is created only once the code is confirmed;
 *  - a {@see User}, for a forgotten password, for accounts registered
 *    before deferred sign-up existed, and for re-verifying an address.
 */
class ClientEmailOtpService
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Stored in place of a hash when Twilio holds the code, so the code is
     * checked with Twilio even if OTP_DRIVER changes while it is outstanding.
     */
    public const TWILIO_MARKER = 'twilio-verify';

    public function __construct(private readonly TwilioVerifyClient $twilio) {}

    /**
     * Issue a fresh OTP to an existing account, replacing any previous one.
     *
     * @param  string  $purpose  One of the ClientEmailOtpNotification::PURPOSE_* values; only changes the email's wording.
     */
    public function issue(User $user, string $purpose = ClientEmailOtpNotification::PURPOSE_VERIFY): void
    {
        if ($this->usesTwilio()) {
            $this->sendWithTwilio($user->email, (string) $user->first_name, $purpose);
            $user->forceFill($this->twilioAttributes())->save();
            $this->startCooldown($user->email);

            return;
        }

        $this->assertMailIsDelivered();

        $code = $this->generateCode($user->email);

        $user->forceFill($this->codeAttributes($code))->save();

        $user->notify(new ClientEmailOtpNotification($code, self::CODE_TTL_MINUTES, $purpose));

        $this->startCooldown($user->email);
    }

    /** Issue a fresh OTP for an in-flight registration. */
    public function issueForRegistration(PendingRegistration $registration): void
    {
        if ($this->usesTwilio()) {
            $this->sendWithTwilio($registration->email, (string) $registration->first_name, ClientEmailOtpNotification::PURPOSE_VERIFY);
            $registration->forceFill($this->twilioAttributes())->save();
            $this->startCooldown($registration->email);

            return;
        }

        $this->assertMailIsDelivered();

        $code = $this->generateCode($registration->email);

        $registration->forceFill($this->codeAttributes($code))->save();

        $registration->notify(new ClientEmailOtpNotification($code, self::CODE_TTL_MINUTES));

        $this->startCooldown($registration->email);
    }

    /**
     * The `log` and `array` mailers report success without sending anything.
     * In production that would leave the user waiting for a code that never
     * comes (e.g. `MAIL_MAILER` missing on Render), so fail instead: callers
     * log the reason to stderr and tell the user the code could not be sent.
     */
    private function assertMailIsDelivered(): void
    {
        if (! app()->isProduction()) {
            return;
        }

        $mailer = (string) config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");

        if (in_array($transport, ['log', 'array'], true)) {
            throw new RuntimeException(sprintf(
                'MAIL_MAILER is "%s", which does not send email. Set MAIL_MAILER=brevo-api with BREVO_API_KEY.',
                $mailer,
            ));
        }
    }

    /** Verify a submitted code for an existing account; marks it verified. */
    public function verify(User $user, string $code): User
    {
        $this->assertCodeUsable($user->email_otp_hash, $user->email_otp_expires_at, (int) $user->email_otp_attempts);

        if (! $this->matches((string) $user->email_otp_hash, $user->email, $code)) {
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

        if (! $this->matches((string) $registration->email_otp_hash, $registration->email, $code)) {
            $registration->forceFill(['email_otp_attempts' => $registration->email_otp_attempts + 1])->save();

            throw $this->incorrectCodeException($registration->email_otp_attempts);
        }

        return $registration;
    }

    public function usesTwilio(): bool
    {
        return config('client-auth.otp_driver') === 'twilio';
    }

    /** Whether $code is the code sent to $email: checked by Twilio when it holds it. */
    private function matches(string $storedHash, string $email, string $code): bool
    {
        return $storedHash === self::TWILIO_MARKER
            ? $this->twilio->checkEmailCode($email, $code)
            : Hash::check($code, $storedHash);
    }

    /**
     * Twilio sends the code. Its email template (SendGrid, set on the Verify
     * service) can use {{first_name}}, {{purpose}} and {{minutes}}; a separate
     * reset template can be set with TWILIO_VERIFY_RESET_TEMPLATE_ID.
     */
    private function sendWithTwilio(string $email, string $firstName, string $purpose): void
    {
        if ($this->isCoolingDown($email)) {
            throw new ApiException('Please wait before requesting another code.', 429);
        }

        $isReset = $purpose === ClientEmailOtpNotification::PURPOSE_PASSWORD_RESET;

        $this->twilio->sendEmailCode(
            $email,
            [
                'first_name' => $firstName !== '' ? $firstName : 'there',
                'purpose' => $isReset ? 'reset your SkillServe password' : 'verify your SkillServe account',
                'minutes' => (string) self::CODE_TTL_MINUTES,
            ],
            $isReset ? (config('services.twilio.verify_reset_template_id') ?: null) : null,
        );
    }

    /** @return array<string, mixed> */
    private function twilioAttributes(): array
    {
        return [
            'email_otp_hash' => self::TWILIO_MARKER,
            'email_otp_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'email_otp_attempts' => 0,
        ];
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
