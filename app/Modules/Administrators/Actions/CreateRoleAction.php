<?php

namespace App\Modules\Administrators\Actions;

use App\Models\User;
use App\Modules\Administrators\Support\SystemRole;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;
use Spatie\Permission\Models\Role;

/**
 * Single unit of work: persist a new role.
 */
final class CreateRoleAction extends BaseAction
{
    /**
     * @param  array{name: string, description?: string|null, permissions?: array<int, string>}  $validated
     */
    public function handle(array $validated, User $actor): Role
    {
        $permissions = $validated['permissions'] ?? [];

        if (! SystemRole::mayGrantProtectedPermissions($actor)
            && SystemRole::containsProtectedPermissions($permissions)) {
            throw new ApiException(
                'Protected permissions may only be granted by a super administrator.',
                403,
                errors: ['permissions' => ['Protected permissions may only be granted by a super administrator.']],
            );
        }

        $role = Role::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'guard_name' => 'web',
        ]);

        if ($permissions !== []) {
            $role->syncPermissions($permissions);
        }

        return $role;
    }
}
