<?php

namespace App\Modules\Administrators\Services;

use App\Models\User;
use App\Modules\Administrators\Actions\CreateRoleAction;
use App\Modules\Administrators\Actions\DeleteRoleAction;
use App\Modules\Administrators\Actions\SyncRolePermissionsAction;
use App\Modules\Administrators\Actions\UpdateRoleAction;
use App\Modules\Administrators\Events\RoleCreated;
use App\Modules\Administrators\Events\RoleDeleted;
use App\Modules\Administrators\Events\RolePermissionsSynced;
use App\Modules\Administrators\Events\RoleUpdated;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\Permission\Models\Role;

/**
 * Orchestrates role management (CRUD + permission matrix sync).
 */
class RoleService extends BaseService
{
    private const SORTABLE = ['name', 'created_at'];

    public function __construct(
        private readonly CreateRoleAction $createRoleAction,
        private readonly UpdateRoleAction $updateRoleAction,
        private readonly DeleteRoleAction $deleteRoleAction,
        private readonly SyncRolePermissionsAction $syncRolePermissionsAction,
    ) {}

    /**
     * Paginated, searchable, sortable role listing with eager-loaded
     * permissions (N+1 safe).
     *
     * @param  array{search?: string, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = Role::query()->with('permissions');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$term]);
            });
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 15))));
    }

    /**
     * Load a single role with its permissions.
     */
    public function show(Role $role): Role
    {
        $role->load('permissions');

        return $role;
    }

    /**
     * Create a role and record the activity.
     *
     * @param  array<string, mixed>  $validated
     */
    public function store(array $validated, User $actor): Role
    {
        return $this->transaction(function () use ($validated, $actor): Role {
            $role = $this->createRoleAction->handle($validated, $actor);

            event(new RoleCreated(role: $role, actor: $actor, data: $validated));

            return $role->load('permissions');
        });
    }

    /**
     * Update a role and record the activity.
     *
     * @param  array<string, mixed>  $validated
     */
    public function update(Role $role, array $validated, User $actor): Role
    {
        return $this->transaction(function () use ($role, $validated, $actor): Role {
            $before = $role->only(['name', 'description']);

            $this->updateRoleAction->handle($role, $validated);

            event(new RoleUpdated(
                role: $role,
                actor: $actor,
                before: $before,
                after: $role->only(['name', 'description']),
            ));

            return $role->load('permissions');
        });
    }

    /**
     * Delete a role and record the activity.
     */
    public function destroy(Role $role, User $actor): void
    {
        $this->transaction(function () use ($role, $actor): void {
            $name = $role->name;

            $this->deleteRoleAction->handle($role);

            event(new RoleDeleted(role: $role, actor: $actor, name: $name));
        });
    }

    /**
     * Sync a role's permissions from the matrix and record the activity.
     *
     * @param  array<int, string>  $permissions
     */
    public function syncPermissions(Role $role, array $permissions, User $actor): Role
    {
        return $this->transaction(function () use ($role, $permissions, $actor): Role {
            $this->syncRolePermissionsAction->handle($role, $permissions, $actor);

            $role->load('permissions');

            event(new RolePermissionsSynced(
                role: $role,
                actor: $actor,
                permissions: $role->permissions->pluck('name')->values()->all(),
            ));

            return $role;
        });
    }
}
