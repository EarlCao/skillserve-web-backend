<?php

namespace App\Modules\Administrators\Actions;

use App\Modules\Administrators\Support\SystemRole;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;
use Spatie\Permission\Models\Role;

/**
 * Single unit of work: update a role's name/description.
 *
 * The system default role may not be renamed (the whole app relies on the
 * literal "super-admin" name for gate bypasses and guards).
 */
final class UpdateRoleAction extends BaseAction
{
    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ApiException when renaming the system role.
     */
    public function handle(Role $role, array $validated): Role
    {
        if (
            $role->name === SystemRole::SUPER_ADMIN
            && isset($validated['name'])
            && $validated['name'] !== $role->name
        ) {
            throw new ApiException(
                'The system default role cannot be renamed.',
                422,
                errors: ['name' => ['The system default role cannot be renamed.']],
            );
        }

        $role->update(array_intersect_key(
            $validated,
            array_flip(['name', 'description']),
        ));

        return $role;
    }
}
