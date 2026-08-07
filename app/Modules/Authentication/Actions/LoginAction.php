<?php

namespace App\Modules\Authentication\Actions;

use App\Models\User;
use App\Modules\Users\Events\UserUnbanned;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Hash;

/**
 * Single unit of work: verify credentials against the users table.
 */
final class LoginAction extends BaseAction
{
    /**
     * @throws ApiException when the credentials are invalid or the account is
     *                      deactivated.
     */
    public function handle(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new ApiException('Invalid email or password.', 401);
        }

        // A temporary ban whose timer has expired is lifted automatically —
        // the account returns to "active" and the user can sign in again.
        // (The users:unban-expired scheduler also runs this as a batch.)
        if ($user->banExpired()) {
            $user->update([
                'status' => 'active',
                'banned_until' => null,
                'unban_reason' => 'Temporary ban expired.',
                'activated_at' => now(),
                'activated_by' => null,
            ]);

            $user->refresh();

            // Audit trail + notification email (actor null: system lift).
            event(new UserUnbanned(user: $user, actor: null, reason: 'Temporary ban expired.'));
        }

        // Deactivated accounts must not be able to sign in (Administrator
        // Management module).
        if (! $user->isActive()) {
            throw new ApiException('Your account has been deactivated. Contact an administrator.', 403);
        }

        return $user;
    }
}
