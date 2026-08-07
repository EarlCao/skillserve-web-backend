<?php

namespace App\Modules\Authentication\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Hash;

/**
 * Single unit of work: verify credentials against the users table.
 */
final class LoginAction extends BaseAction
{
    /**
     * @throws ApiException when the credentials are invalid.
     */
    public function handle(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new ApiException('Invalid email or password.', 401);
        }

        return $user;
    }
}
