<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Modules\Locations\Services\PhAddressService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deferred mobile sign-up.
 *
 * A registration is parked in `pending_registrations` and only becomes a
 * real account once it has been seen through. Abandoning it therefore leaves
 * nothing behind: the address stays free and the same person can sign up
 * again immediately, which is the whole point of the table.
 *
 * Email and Google sign-ups take the same three steps:
 *  1. {@see start()} parks the details and emails a 6-digit code;
 *  2. {@see verify()} confirms the code;
 *  3. {@see completeWithPassword()} takes the password and creates the account.
 *
 * Sign-ups parked by older app versions already carry a password and are
 * created by {@see complete()} as soon as the code is confirmed.
 */
class PendingRegistrationService
{
    public function __construct(
        private readonly ClientEmailOtpService $otpService,
        private readonly ClientAccountCreator $accountCreator,
        private readonly ClientSessionService $sessionService,
        private readonly PhAddressService $addresses,
    ) {}

    /**
     * Park a sign-up and email its verification code. The returned row
     * carries the registration token in plain text, for this response only.
     *
     * @param  array{first_name: string, last_name: string, email: string, password?: string|null, business_name?: string|null, specialization?: string|null, experience_years?: int|null, bio?: string|null}  $validated
     * @param  string|null  $googleSub  The Google identity the sign-up started from.
     */
    public function start(array $validated, int $roleId, ?string $googleSub = null): PendingRegistration
    {
        $this->pruneExpired();

        $email = strtolower((string) $validated['email']);
        // Resolved before anything is written, so an address in the wrong
        // city is a validation error rather than a half-stored sign-up.
        $address = $this->addresses->resolve($validated['address_details'] ?? null, 'address', PhAddressService::STREET, 'address_details');

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

        $token = Str::random(64);

        $registration = DB::transaction(function () use ($validated, $roleId, $email, $address, $googleSub, $token): PendingRegistration {
            // A repeat sign-up for the same address replaces the previous
            // attempt, so the newest details (and role) always win.
            PendingRegistration::query()->where('email', $email)->delete();

            return PendingRegistration::create([
                'email' => $email,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                // Chosen after the code is confirmed; only older app versions send it here.
                'password' => $validated['password'] ?? null,
                'google_sub' => $googleSub,
                'registration_token_hash' => $this->hashToken($token),
                'role_id' => $roleId,
                'business_name' => $validated['business_name'] ?? null,
                'specialization' => $validated['specialization'] ?? null,
                'experience_years' => $validated['experience_years'] ?? 0,
                'bio' => $validated['bio'] ?? null,
                'birthday' => $validated['birthday'] ?? null,
                ...$address['columns'],
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

        $registration->refresh();
        $registration->plainRegistrationToken = $token;

        return $registration;
    }

    /** Email a fresh code for an in-flight sign-up. */
    public function resend(PendingRegistration $registration): void
    {
        $this->otpService->issueForRegistration($registration);
    }

    /**
     * Confirm the emailed code. The password is chosen next, with
     * {@see completeWithPassword()}. Confirming again is harmless, so a
     * repeated submission of the same screen does not fail.
     */
    public function verify(PendingRegistration $registration, string $code): PendingRegistration
    {
        if ($registration->hasVerifiedEmail()) {
            return $registration;
        }

        $this->otpService->verifyRegistration($registration, $code);

        $registration->forceFill([
            'email_verified_at' => now(),
            // The code is spent; the column itself is NOT NULL.
            'email_otp_hash' => '',
            'email_otp_attempts' => 0,
        ])->save();

        return $registration;
    }

    /**
     * Confirm the code of a sign-up that already carries its password (one
     * parked by an older app version) and create the account.
     *
     * @return array<string, mixed>
     */
    public function complete(PendingRegistration $registration, string $code): array
    {
        $this->otpService->verifyRegistration($registration, $code);

        return $this->promote($registration);
    }

    /**
     * The last step: set the password on a sign-up whose code was confirmed
     * and create the account. The registration token proves this is the
     * device that started the sign-up, so the email alone is not enough.
     *
     * @return array<string, mixed> Session payload (user + tokens).
     */
    public function completeWithPassword(string $email, string $registrationToken, string $password): array
    {
        $registration = $this->findUnexpired($email);

        if (! $registration || ! $this->tokenMatches($registration, $registrationToken)) {
            throw new ApiException('This sign-up has expired. Please start again.', 422);
        }

        if (! $registration->hasVerifiedEmail()) {
            throw new ApiException('Enter the code we emailed you before choosing a password.', 422);
        }

        return $this->promote($registration, $password);
    }

    /**
     * Turn a registration into the real account and sign it in.
     *
     * @param  string|null  $password  Plain password from the last step; null keeps the one parked with the sign-up.
     * @return array<string, mixed>
     */
    private function promote(PendingRegistration $registration, ?string $password = null): array
    {
        $user = DB::transaction(function () use ($registration, $password): User {
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
                // Plain or already hashed: the `hashed` cast handles both.
                'password' => $password ?? $claimed->password,
                'role_id' => $claimed->role_id,
                'business_name' => $claimed->business_name,
                'specialization' => $claimed->specialization,
                'experience_years' => $claimed->experience_years,
                'bio' => $claimed->bio,
                'birthday' => $claimed->birthday,
                ...$this->addressOf($claimed),
            ], $claimed->google_sub);

            $claimed->delete();

            return $user;
        });

        $this->otpService->clearCooldown($user->email);

        return $this->sessionService->issue($user);
    }

    /**
     * Drop an in-flight sign-up after the user backed out of it. Guarded by
     * the registration token (or, for sign-ups parked by older app versions,
     * the password chosen at registration) so the endpoint cannot be used to
     * cancel somebody else's sign-up.
     */
    public function cancel(string $email, ?string $password, ?string $registrationToken = null): void
    {
        $registration = $this->findUnexpired($email);

        if (! $registration) {
            return;
        }

        $owned = ($registrationToken !== null && $this->tokenMatches($registration, $registrationToken))
            || ($password !== null && $registration->password !== null && Hash::check($password, $registration->password));

        if (! $owned) {
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

    private function tokenMatches(PendingRegistration $registration, string $token): bool
    {
        return $registration->registration_token_hash !== null
            && hash_equals($registration->registration_token_hash, $this->hashToken($token));
    }

    /** SHA-256 is enough: the token is 64 random characters, not a password. */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Remove sign-ups that were never verified within their window. */
    private function pruneExpired(): void
    {
        PendingRegistration::query()->where('expires_at', '<=', now())->delete();
    }

    /**
     * The structured address parked with a sign-up, plus its formatted text
     * for `users.address`.
     *
     * @return array<string, mixed>
     */
    private function addressOf(PendingRegistration $registration): array
    {
        $columns = array_filter($registration->only([
            'address_region_code', 'address_province_code', 'address_city_code',
            'address_barangay_code', 'address_street', 'address_postal_code',
        ]), fn ($value) => $value !== null);

        if (! isset($columns['address_barangay_code'])) {
            return [];
        }

        $resolved = $this->addresses->resolve([
            'barangay_code' => $columns['address_barangay_code'],
            'street' => $columns['address_street'] ?? null,
            'postal_code' => $columns['address_postal_code'] ?? null,
        ], 'address', PhAddressService::STREET, 'address_details');

        return [...$resolved['columns'], 'address' => $resolved['formatted']];
    }
}
