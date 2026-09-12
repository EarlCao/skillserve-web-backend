<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\ClientAuthentication\Notifications\ClientEmailVerificationNotification;
use App\Modules\ClientAuthentication\Notifications\ClientPasswordResetNotification;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

class ClientAuthenticationService
{
    public function __construct(
        private readonly ClientSessionService $sessionService,
        private readonly ClientEmailOtpService $otpService,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string}  $validated
     */
    public function register(array $validated): array
    {
        $session = DB::transaction(function () use ($validated): array {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'name' => trim($validated['first_name'].' '.$validated['last_name']),
                'email' => $validated['email'],
                'password' => $validated['password'],
                'user_type' => 'customer',
                'status' => 'active',
                'email_verified_at' => null,
            ]);

            return $this->sessionService->issue($user);
        });

        try {
            $this->otpService->issue($session['user']);
        } catch (\Throwable $e) {
            Log::channel('stderr')->error('Failed to send verification OTP for client registration.', [
                'user_id' => $session['user']->id,
                'email' => $session['user']->email,
                'error' => $e->getMessage(),
            ]);
        }

        return $session;
    }

    /**
     * @param  array{email: string, password: string}  $validated
     */
    public function login(array $validated): array
    {
        $user = User::query()
            ->where('email', $validated['email'])
            ->whereIn('user_type', ['customer', 'provider'])
            ->doesntHave('roles')
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw new ApiException('Invalid email or password.', 401);
        }

        if (! $user->isActive()) {
            throw new ApiException('Your account is not active.', 403);
        }

        if (! $user->hasVerifiedEmail()) {
            throw new ApiException('Please verify your email address before signing in.', 403);
        }

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

    private function clientQuery(): Builder
    {
        return User::query()
            ->where('user_type', 'customer')
            ->doesntHave('roles');
    }

    private function invalidResetException(?string $status = null): ApiException
    {
        $message = $status === Password::RESET_THROTTLED
            ? 'Please wait before requesting another password reset.'
            : 'The password reset token is invalid or has expired.';

        return new ApiException($message, 422, errors: ['token' => [$message]]);
    }
}
