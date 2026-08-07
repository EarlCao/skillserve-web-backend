<?php

namespace App\Modules\Administrators\Actions;

use App\Shared\Actions\BaseAction;
use Spatie\Permission\Models\Role;

/**
 * Single unit of work: persist a new role.
 */
final class CreateRoleAction extends BaseAction
{
    /**
     * @param  array{name: string, description?: string|null}  $validated
     */
    public function handle(array $validated): Role
    {
        return Role::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'guard_name' => 'web',
        ]);
    }
}
