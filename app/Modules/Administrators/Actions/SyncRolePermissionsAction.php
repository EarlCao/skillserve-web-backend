<?php

namespace App\Modules\Administrators\Actions;

use App\Modules\Administrators\Support\SystemRole;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;
use Spatie\Permission\Models\Role;

/**
 * Single unit of work: sync a role's permission set from the matrix.
 *
 * Super administrator permissions are immutable — changing them could lock
 * the system's only bypass out of the admin console.
 */
final class SyncRolePermissionsAction extends BaseAction
{
    /**
     * @param  array<int, string>  $permissions
     *
     * @throws ApiException when modifying super administrator permissions.
     */
    public function handle(Role $role, array $permissions): Role
    {
        if ($role->name === SystemRole::SUPER_ADMIN) {
            throw new ApiException(
                'Super administrator permissions cannot be modified.',
                422,
                errors: ['permissions' => ['Super administrator permissions cannot be modified.']],
            );
        }

        $role->syncPermissions($permissions);

        return $role;
    }
}
