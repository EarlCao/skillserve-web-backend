<?php

namespace App\Modules\Administrators\Services;

use App\Modules\Administrators\Resources\PermissionResource;
use Spatie\Permission\Models\Permission;

/**
 * Builds the permission matrix: every permission in the catalog, grouped by
 * feature module so the frontend can render one group per module.
 */
class PermissionService
{
    /**
     * @return array<int, array{module: string, permissions: array<int, array<string, mixed>>}>
     */
    public function matrix(): array
    {
        $permissions = Permission::query()
            ->orderBy('name')
            ->get();

        return $permissions
            ->groupBy(fn (Permission $permission) => (new PermissionResource($permission))->module())
            ->map(fn ($group, $module) => [
                'module' => $module,
                'permissions' => $group
                    ->map(fn (Permission $permission) => (new PermissionResource($permission))->resolve())
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
