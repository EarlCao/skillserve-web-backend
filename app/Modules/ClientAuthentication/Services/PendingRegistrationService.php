<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deferred mobile sign-up.
 *
 * A registration is parked in `pending_registrations` and only becomes a
 * real account once the emailed OTP is confirmed. Abandoning the OTP screen
 * therefore leaves nothing behind: the address stays free and the same
 * person can sign up again immediately, which is the whole point of the
 * table.
 */
class PendingRegistrationService
{
    public function __construct(
        private readonly ClientEmailOtpService $otpService,
        private readonly ClientAccountCreator $accountCreator,
        private readonly ClientSessionService $sessionService,
    ) {}

    /**
     * Park a sign-up and email its verification code.
     *
     * @param  array{first_name: string, last_name: string, email: string, password: string, business_name?: string|null, specialization?: string|null, experience_years?: int|null, bio?: string|null}  $validated
     */
    public function start(array $validated, int $roleId): PendingRegistration
    {
        $this->pruneExpired();

        $email = strtolower((string) $validated['email']);

        // A code sent moments ago is still valid. Refuse before replacing
        // anything, so signing up twice in quick succession cannot destroy
        // the code the user is already holding.
        if ($this->otpService->isCoolingDown($email)) {
            $inFlight = $this->findUnexpired($email);

            throw new ApiException(
                $inFlight
                    ? 'We already sent a code to this email. Enter it to finish signing up, or wait a moment to request a new one.'
                    : 'Please wait before requesting another code.',
                429,
                meta: $inFlight ? ['verification_required' => true, 'email' => $email] : [],
            );
        }

        $registration = DB::transaction(function () use ($validated, $roleId, $email): PendingRegistration {
            // A repeat sign-up for the same address replaces the previous
            // attempt, so the newest details (and role) always win.
            PendingRegistration::query()->where('email', $email)->delete();

            return PendingRegistration::create([
                'email' => $email,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'password' => $validated['password'],
                'role_id' => $roleId,
                'business_name' => $validated['business_name'] ?? null,
                'specialization' => $validated['specialization'] ?? null,
                'experience_years' => $validated['experience_years'] ?? 0,
                'bio' => $validated['bio'] ?? null,
                // Placeholders: issueForRegistration() writes the real code.
                'email_otp_hash' => '',
                'email_otp_expires_at' => now(),
                'expires_at' => now()->addHours(PendingRegistration::LIFETIME_HOURS),
            ]);
        });

        try {
            $this->otpService->issueForRegistration($registration);
        } catch (ApiException $e) {
            // Lost a race for the cooldown: drop the row rather than leave
            // a registration behind that has no code to verify against.
            $registration->delete();

            throw $e;
        } catch (Throwable $e) {
            // Mail failures must be visible in the platform log stream (the
            // default channel writes inside the container).
            Log::channel('stderr')->error('Failed to send registration OTP.', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            $registration->delete();

            throw new ApiException(
                'We could not send your verification code. Please try again in a moment.',
                503,
            );
        }

        return $registration->fresh();
    }

    /** Email a fresh code for an in-flight sign-up. */
    public function resend(PendingRegistration $registration): void
    {
        $this->otpService->issueForRegistration($registration);
    }

    /**
     * Confirm the code and create the real account, returning its session.
     *
     * @return array<string, mixed>
     */
    public function complete(PendingRegistration $registration, string $code): array
    {
        $this->otpService->verifyRegistration($registration, $code);

        $user = DB::transaction(function () use ($registration): User {
            // Claim the registration under a row lock: a double submission
            // (the OTP screen auto-submits on the sixth digit) must not
            // race two account creations onto the same email.
            $claimed = PendingRegistration::query()
                ->whereKey($registration->getKey())
                ->lockForUpdate()
                ->first();

            if (! $claimed) {
                throw new ApiException('This sign-up has already been completed. Please sign in.', 409);
            }

            // The address could also have been claimed by another sign-up
            // while this one waited for its code.
            if (User::query()->where('email', $claimed->email)->withTrashed()->exists()) {
                $claimed->delete();

                throw new ApiException('This email address is already registered. Please sign in instead.', 409);
            }

            $user = $this->accountCreator->create([
                'first_name' => $claimed->first_name,
                'last_name' => $claimed->last_name,
                'email' => $claimed->email,
                'password' => $claimed->password,
                'role_id' => $claimed->role_id,
                'business_name' => $claimed->business_name,
                'specialization' => $claimed->specialization,
                'experience_years' => $claimed->experience_years,
                'bio' => $claimed->bio,
            ]);

            $claimed->delete();

            return $user;
        });

        $this->otpService->clearCooldown($user->email);

        return $this->sessionService->issue($user);
    }

    /**
     * Drop an in-flight sign-up after the user backed out of verification.
     * Guarded by the password chosen at registration so the endpoint cannot
     * be used to cancel somebody else's sign-up.
     */
    public function cancel(string $email, string $password): void
    {
        $registration = $this->findUnexpired($email);

        if (! $registration || ! Hash::check($password, $registration->password)) {
            return;
        }

        $registration->delete();

        // Let the user start over straight away instead of waiting out the
        // resend window of the code they abandoned.
        $this->otpService->clearCooldown($email);
    }

    /** The live sign-up for an address, if one is still verifiable. */
    public function findUnexpired(string $email): ?PendingRegistration
    {
        return PendingRegistration::query()
            ->where('email', strtolower($email))
            ->unexpired()
            ->first();
    }

    /** Remove sign-ups that were never verified within their window. */
    private function pruneExpired(): void
    {
        PendingRegistration::query()->where('expires_at', '<=', now())->delete();
    }
}
