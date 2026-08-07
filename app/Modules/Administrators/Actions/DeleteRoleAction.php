<?php

namespace App\Modules\Administrators\Actions;

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
        if ($role->name === SystemRole::SUPER_ADMIN) {
            throw new ApiException(
                'The system default role cannot be deleted.',
                422,
                errors: ['role' => ['The system default role cannot be deleted.']],
            );
        }

        $role->users()->detach();
        $role->permissions()->detach();
        $role->delete();
    }
}
