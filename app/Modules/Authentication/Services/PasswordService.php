<?php

namespace App\Modules\Authentication\Services;

use App\Models\User;
use App\Modules\Authentication\Actions\ChangePasswordAction;
use App\Modules\Authentication\Events\PasswordChanged;
use App\Modules\Authentication\Notifications\AdminPasswordResetNotification;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Password management for administrators: changing it while signed in, and
 * resetting a forgotten one by email.
 */
class PasswordService
{
    public function __construct(
        private readonly ChangePasswordAction $changePasswordAction,
    ) {}

    /**
     * Verify the current password, store the new one, and notify the rest of
     * the application (activity log, listeners, ...).
     *
     * @param  array{current_password: string, password: string}  $validated
     */
    public function changePassword(User $user, array $validated): void
    {
        $this->changePasswordAction->handle(
            $user,
            $validated['current_password'],
            $validated['password'],
            $user->currentAccessToken()?->id,
        );

        event(new PasswordChanged(
            user: $user,
            ip: request()->ip(),
            userAgent: request()->userAgent(),
        ));
    }

    /**
     * Email a reset link to an active administrator. The response never
     * reveals whether the address belongs to one, so the endpoint cannot be
     * used to discover admin accounts.
     */
    public function requestReset(string $email): void
    {
        $admin = $this->administrator($email);

        if (! $admin) {
            return;
        }

        Password::broker('admins')->sendResetLink(
            ['email' => $email],
            function (User $user, string $token): string {
                $user->notify(new AdminPasswordResetNotification($token));

                return Password::RESET_LINK_SENT;
            },
        );
    }

    /**
     * Set a new password from a valid reset token and end every session, so
     * whoever knew the old password is signed out.
     *
     * @param  array{token: string, email: string, password: string, password_confirmation: string}  $validated
     */
    public function resetPassword(array $validated): void
    {
        if (! $this->administrator($validated['email'])) {
            throw $this->invalidReset();
        }

        $reset = null;
        $status = Password::broker('admins')->reset(
            $validated,
            function (User $user, string $password) use (&$reset): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->password = $password;
                    $user->save();
                    $user->tokens()->delete();
                });
                $reset = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $reset) {
            throw $this->invalidReset($status);
        }

        event(new PasswordChanged(user: $reset, ip: request()->ip(), userAgent: request()->userAgent()));
    }

    /** An active account holding an admin role; mobile accounts never match. */
    private function administrator(string $email): ?User
    {
        $user = User::query()->where('email', $email)->first();

        return $user && $user->isActive() && $user->roles()->exists() ? $user : null;
    }

    private function invalidReset(?string $status = null): ApiException
    {
        return new ApiException(
            $status === Password::RESET_THROTTLED
                ? 'Please wait before trying again.'
                : 'This password reset link is invalid or has expired.',
            422,
            errors: ['token' => ['Request a new password reset link.']],
        );
    }
}
