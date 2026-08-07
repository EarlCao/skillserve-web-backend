<?php

namespace App\Modules\Administrators\Actions;

use App\Models\User;
use App\Modules\Administrators\Support\SystemRole;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: activate or deactivate an administrator account.
 *
 * Guards:
 *  - an administrator can never deactivate their own account;
 *  - the last active super administrator can never be deactivated.
 */
final class SetAdministratorStatusAction extends BaseAction
{
    /**
     * @throws ApiException when one of the activation guards is violated.
     */
    public function handle(User $administrator, User $actor, string $status): User
    {
        if ($administrator->status === $status) {
            return $administrator;
        }

        if ($status === 'inactive') {
            $this->assertCanDeactivate($administrator, $actor);
        }

        $administrator->update(['status' => $status]);

        return $administrator;
    }

    /**
     * @throws ApiException
     */
    private function assertCanDeactivate(User $administrator, User $actor): void
    {
        if ($administrator->is($actor)) {
            throw new ApiException(
                'You cannot deactivate your own account.',
                422,
                errors: ['status' => ['You cannot deactivate your own account.']],
            );
        }

        // Super administrators are a fixed system role: their account status
        // can never be changed.
        if ($administrator->hasRole(SystemRole::SUPER_ADMIN)) {
            throw new ApiException(
                'Super administrator accounts cannot be deactivated.',
                422,
                errors: ['status' => ['Super administrator accounts cannot be deactivated.']],
            );
        }
    }
}
