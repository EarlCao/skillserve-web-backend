<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\ClientRefreshToken;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Modules\ClientAuthentication\Notifications\ClientEmailOtpNotification;
use App\Modules\ClientAuthentication\Notifications\ClientEmailVerificationNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Enums\AccountRole;
use App\Shared\Exceptions\AccountRestrictedException;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

class ClientAuthenticationService
{
    public function __construct(
        private readonly ClientSessionService $sessionService,
        private readonly ClientEmailOtpService $otpService,
        private readonly PendingRegistrationService $pendingRegistrations,
    ) {}

    /**
     * Start a customer sign-up. No `users` row is created yet: the account
     * is written only once the emailed OTP is confirmed, so backing out of
     * verification leaves the address free.
     *
     * @param  array{first_name: string, last_name: string, email: string, password: string}  $validated
     */
    public function register(array $validated): PendingRegistration
    {
        return $this->pendingRegistrations->start($validated, AccountRole::Customer->value);
    }

    /**
     * Drop a sign-up whose owner backed out of email verification.
     *
     * Normally this only removes the parked registration, guarded by its
     * registration token. Accounts created before sign-ups were deferred can
     * still sit unverified in `users`, so those are hard-deleted here too,
     * guarded by their password. Failures are silent so the response cannot
     * enumerate accounts.
     *
     * @param  array{email: string, password?: string|null, registration_token?: string|null}  $validated
     */
    public function cancelUnverifiedRegistration(array $validated): void
    {
        $password = isset($validated['password']) ? (string) $validated['password'] : null;

        $this->pendingRegistrations->cancel($validated['email'], $password, $validated['registration_token'] ?? null);

        if ($password === null) {
            return;
        }

        $user = User::query()
            ->where('email', $validated['email'])
            ->mobileAccounts()
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return;
        }

        if ($user->hasVerifiedEmail()) {
            return;
        }

        DB::transaction(function () use ($user): void {
            if ($user->user_type === 'provider') {
                ProviderProfile::query()->where('user_id', $user->id)->delete();
            }

            // Refresh tokens restrict user deletion, so remove them
            // explicitly (plus any access tokens and notifications).
            ClientRefreshToken::query()
                ->where('user_id', $user->id)
                ->delete();
            $user->tokens()->delete();
            $user->notifications()->delete();
            $user->forceDelete();
        });

        $this->otpService->clearCooldown($user->email);
    }

    /**
     * @param  array{email: string, password: string}  $validated
     */
    public function login(array $validated): array
    {
        $user = User::query()
            ->where('email', $validated['email'])
            ->mobileAccounts()
            ->first();

        if (! $user) {
            // A sign-up that never confirmed its code has no account yet.
            // Recognising it here turns a baffling "invalid credentials"
            // into a prompt to finish verifying.
            $this->assertNoPendingRegistration($validated['email'], $validated['password']);

            throw new ApiException('Invalid email or password.', 401);
        }

        if (! Hash::check($validated['password'], $user->password)) {
            throw new ApiException('Invalid email or password.', 401);
        }

        if (! $user->isActive()) {
            throw AccountRestrictedException::for($user);
        }

        if (! $user->hasVerifiedEmail()) {
            throw new ApiException('Please verify your email address before signing in.', 403);
        }

        return $this->sessionService->issue($user);
    }

    /**
     * Issue a mobile session for an account that has just proved itself
     * (for example by confirming an OTP on a pre-existing account).
     *
     * @return array<string, mixed>
     */
    public function issueSession(User $user): array
    {
        return $this->sessionService->issue($user);
    }

    public function rotate(string $refreshToken): array
    {
        return $this->sessionService->rotate($refreshToken);
    }

    public function logout(User $user): void
    {
        $this->sessionService->revokeAll($user);
    }

    public function sendVerificationNotification(User $user): void
    {
        if ($user->isClientAccount() && ! $user->hasVerifiedEmail()) {
            $user->notify(new ClientEmailVerificationNotification);
        }
    }

    /**
     * Send the email-verification link to any unverified mobile account
     * (customers and mobile-registered providers).
     */
    public function sendMobileVerificationNotification(User $user): void
    {
        if ($user->isMobileAccount() && ! $user->hasVerifiedEmail()) {
            $user->notify(new ClientEmailVerificationNotification);
        }
    }

    public function verifyEmail(User $user, string $hash): User
    {
        if (! $user->isMobileAccount() || ! $user->isActive() || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new ApiException('The email verification link is invalid.', 403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $user->fresh();
    }

    /**
     * @param  array{current_password: string, password: string}  $validated
     */
    public function changePassword(User $user, array $validated): void
    {
        if (! Hash::check($validated['current_password'], $user->password)) {
            throw new ApiException(
                'The current password is incorrect.',
                422,
                errors: ['current_password' => ['The current password is incorrect.']],
            );
        }

        DB::transaction(function () use ($user, $validated): void {
            $user->password = $validated['password'];
            $user->save();
            $this->sessionService->revokeAll($user);
        });
    }

    /**
     * Email a 6-digit code that proves the address before the password is
     * replaced ({@see verifyPasswordResetCode()}).
     *
     * An address with no mobile account is told so (owner decision,
     * 2026-10-10), so the app only moves on to the code screen for a real
     * account; sign-up already reveals whether an address is taken, and the
     * client-auth limiter caps how fast addresses can be tried. A suspended or
     * banned account is refused as at login. An address sent a code moments
     * ago gets no new one: the code already on its way is the one to enter.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = $this->clientQuery()->where('email', $email)->first();

        if ($user === null) {
            $message = 'There is no SkillServe account with this email. Check it, or sign up.';

            throw new ApiException($message, 404, ['email' => [$message]]);
        }

        if (! $user->isActive()) {
            throw AccountRestrictedException::for($user);
        }

        if ($this->otpService->isCoolingDown($user->email)) {
            return;
        }

        try {
            $this->otpService->issue($user, ClientEmailOtpNotification::PURPOSE_PASSWORD_RESET);
        } catch (ApiException) {
            // Lost the race for the resend cooldown: a code is already on its way.
        } catch (Throwable $e) {
            Log::channel('stderr')->error('Failed to send password reset code.', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw new ApiException('We could not send your code. Please try again in a moment.', 503);
        }
    }

    /**
     * Confirm the emailed reset code and hand back a single-use reset token
     * for {@see resetPassword()}, so the new password is chosen on the next
     * screen. Every failure reads the same, so the endpoint cannot enumerate
     * accounts.
     */
    public function verifyPasswordResetCode(string $email, string $code): string
    {
        $user = $this->clientQuery()->where('email', $email)->first();

        if (! $user?->isActive()) {
            throw new ApiException('This verification code has expired. Please request a new one.', 422);
        }

        // Proving the inbox also confirms the address, if it was not already.
        $this->otpService->verify($user, $code);

        return Password::broker('clients')->createToken($user);
    }

    /**
     * @param  array{token: string, email: string, password: string, password_confirmation: string}  $validated
     */
    public function resetPassword(array $validated): void
    {
        $user = $this->clientQuery()->where('email', $validated['email'])->first();

        if (! $user || ! $user->isActive()) {
            throw $this->invalidResetException();
        }

        $resetAllowed = true;
        $status = Password::broker('clients')->reset(
            $validated,
            function (User $resetUser, string $password) use (&$resetAllowed): void {
                if (! $resetUser->isMobileAccount() || ! $resetUser->isActive()) {
                    $resetAllowed = false;

                    return;
                }

                DB::transaction(function () use ($resetUser, $password): void {
                    $resetUser->password = $password;
                    $resetUser->save();
                    $this->sessionService->revokeAll($resetUser);
                });
            },
        );

        if (! $resetAllowed || $status !== Password::PASSWORD_RESET) {
            throw $this->invalidResetException($status);
        }
    }

    /**
     * Password reset covers every mobile account: providers sign in through
     * the same endpoints as customers and must be able to reset too.
     */
    private function clientQuery(): Builder
    {
        return User::query()
            ->mobileAccounts();
    }

    /**
     * Tell a half-finished sign-up apart from a wrong password. The status
     * meta lets the app send the user straight back to the OTP screen.
     */
    private function assertNoPendingRegistration(string $email, string $password): void
    {
        $registration = $this->pendingRegistrations->findUnexpired($email);

        if (! $registration || ! Hash::check($password, $registration->password)) {
            return;
        }

        throw new ApiException(
            'Please verify your email address to finish creating your account.',
            403,
            meta: ['verification_required' => true, 'email' => $registration->email],
        );
    }

    private function invalidResetException(?string $status = null): ApiException
    {
        $message = $status === Password::RESET_THROTTLED
            ? 'Please wait before requesting another password reset.'
            : 'The password reset token is invalid or has expired.';

        return new ApiException($message, 422, errors: ['token' => [$message]]);
    }
}
