<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\ClientAuthentication\Models\ClientRefreshToken;
use App\Modules\ClientAuthentication\Models\PendingRegistration;
use App\Modules\ClientAuthentication\Notifications\ClientEmailVerificationNotification;
use App\Modules\ClientAuthentication\Notifications\ClientPasswordResetNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Enums\AccountRole;
use App\Shared\Exceptions\AccountRestrictedException;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

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
     * Normally this only removes the parked registration. Accounts created
     * before sign-ups were deferred can still sit unverified in `users`,
     * so those are hard-deleted here too. Both paths are guarded by the
     * registration password, and failures are silent so the response cannot
     * enumerate accounts.
     *
     * @param  array{email: string, password: string}  $validated
     */
    public function cancelUnverifiedRegistration(array $validated): void
    {
        $this->pendingRegistrations->cancel($validated['email'], (string) $validated['password']);

        $user = User::query()
            ->where('email', $validated['email'])
            ->mobileAccounts()
            ->first();

        if (! $user || ! Hash::check((string) $validated['password'], $user->password)) {
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

    public function requestPasswordReset(string $email): void
    {
        $user = $this->clientQuery()->where('email', $email)->first();

        // Keep the response identical for unknown, administrative, and
        // inactive addresses so the endpoint cannot enumerate accounts.
        if ($user?->isActive()) {
            Password::broker('clients')->sendResetLink(
                ['email' => $email],
                function (User $resetUser, string $token): string {
                    Notification::send($resetUser, new ClientPasswordResetNotification($token));

                    return Password::RESET_LINK_SENT;
                },
            );
        }
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
