<?php

namespace App\Modules\Authentication\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: invalidate the current session token.
 */
final class LogoutAction extends BaseAction
{
    public function handle(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
