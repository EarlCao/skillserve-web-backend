<?php

namespace App\Modules\Administrators\Actions;

use App\Models\User;
use App\Modules\Administrators\Support\SystemRole;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;
use Spatie\Permission\Models\Role;

/**
 * Single unit of work: delete a role, cleaning up its pivot rows first.
 */
final class DeleteRoleAction extends BaseAction
{
    /**
     * @throws ApiException when attempting to delete the system default role.
     */
    public function handle(Role $role): void
    {
        if (SystemRole::isFixed($role)) {
            throw new ApiException(
                'The system default role cannot be deleted.',
                422,
                errors: ['role' => ['The system default role cannot be deleted.']],
            );
        }

        $users = User::query()->where('role_id', $role->getKey())->get();

        $role->users()->detach();
        $role->permissions()->detach();

        // Accounts whose type pointed at this role fall back to their next
        // staff role, or become customers (users.role_id cannot dangle).
        $users->each(fn (User $user) => $user->syncRoleIdFromStaffRoles());

        $role->delete();
    }
}
