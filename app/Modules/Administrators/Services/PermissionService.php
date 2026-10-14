<?php

namespace App\Modules\Administrators\Services;

use App\Modules\Administrators\Resources\PermissionResource;
use Spatie\Permission\Models\Permission;

/**
 * Builds the permission matrix: one group per area of the admin web
 * (config/permission-areas.php), each with its read-only permissions
 * (`view_permissions`) and every permission full control grants
 * (`permissions`, view first). Permissions in no area are listed under
 * "Other" so none is ever hidden.
 */
class PermissionService
{
    /**
     * @return array<int, array{key: string, module: string, description: string, view_permissions: array<int, string>, permissions: array<int, array<string, mixed>>}>
     */
    public function matrix(): array
    {
        $byName = Permission::query()->orderBy('name')->get()->keyBy('name');
        $shape = fn (Permission $permission): array => (new PermissionResource($permission))->resolve();
        $groups = [];

        foreach (config('permission-areas') as $area) {
            $view = array_values(array_filter($area['view'], fn (string $name): bool => $byName->has($name)));
            $all = array_values(array_unique(array_filter([...$area['view'], ...$area['control']], fn (string $name): bool => $byName->has($name))));

            if ($all === []) {
                continue;
            }

            $groups[] = [
                'key' => $area['key'],
                'module' => $area['label'],
                'description' => $area['description'],
                'view_permissions' => $view,
                'permissions' => array_map(fn (string $name): array => $shape($byName[$name]), $all),
            ];

            foreach ($all as $name) {
                $byName->forget($name);
            }
        }

        if ($byName->isNotEmpty()) {
            $groups[] = [
                'key' => 'other',
                'module' => 'Other',
                'description' => 'Permissions not yet placed in an area.',
                'view_permissions' => [],
                'permissions' => $byName->values()->map($shape)->all(),
            ];
        }

        return $groups;
    }
}
