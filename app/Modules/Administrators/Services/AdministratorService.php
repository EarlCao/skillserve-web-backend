<?php

namespace App\Modules\Administrators\Services;

use App\Models\User;
use App\Modules\Administrators\Actions\CreateAdministratorAction;
use App\Modules\Administrators\Actions\SetAdministratorStatusAction;
use App\Modules\Administrators\Actions\UpdateAdministratorAction;
use App\Modules\Administrators\Events\AdministratorCreated;
use App\Modules\Administrators\Events\AdministratorStatusChanged;
use App\Modules\Administrators\Events\AdministratorUpdated;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

/**
 * Orchestrates administrator management: listing (search/filter/sort/paginate),
 * creation, profile updates and activation control. Controllers stay thin.
 */
class AdministratorService extends BaseService
{
    /**
     * Columns that can be sorted on.
     */
    private const SORTABLE = ['name', 'created_at'];

    public function __construct(
        private readonly CreateAdministratorAction $createAdministratorAction,
        private readonly UpdateAdministratorAction $updateAdministratorAction,
        private readonly SetAdministratorStatusAction $setAdministratorStatusAction,
    ) {}

    /**
     * Paginated, searchable, filterable, sortable administrator listing.
     *
     * @param  array{search?: string, status?: string, role?: string, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = User::query()
            // Administrator Management = role-bearing accounts only; plain
            // platform users (customers) are managed in User Management.
            ->has('roles')
            ->with(['roles', 'roles.permissions'])
            ->with('createdBy:id,name');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
            });
        }

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        if ($role = trim((string) ($filters['role'] ?? ''))) {
            $query->role($role);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters));
    }

    /**
     * Load a single administrator with its relations.
     */
    public function show(User $administrator): User
    {
        $administrator->load(['roles', 'roles.permissions', 'createdBy:id,name']);

        return $administrator;
    }

    /**
     * Create an administrator and record the activity.
     *
     * @param  array<string, mixed>  $validated
     */
    public function store(array $validated, User $actor): User
    {
        return $this->transaction(function () use ($validated, $actor): User {
            $administrator = $this->createAdministratorAction->handle($validated, $actor);

            $administrator->load(['roles', 'roles.permissions', 'createdBy:id,name']);

            // Never persist credentials in the audit trail.
            event(new AdministratorCreated(
                administrator: $administrator,
                actor: $actor,
                data: Arr::except($validated, ['password', 'password_confirmation']),
            ));

            return $administrator;
        });
    }

    /**
     * Update an administrator's profile and record the activity.
     *
     * @param  array<string, mixed>  $validated
     */
    public function update(User $administrator, array $validated, User $actor): User
    {
        return $this->transaction(function () use ($administrator, $validated, $actor): User {
            $before = $this->snapshot($administrator);

            $this->updateAdministratorAction->handle($administrator, $validated, $actor);

            $administrator->load(['roles', 'roles.permissions', 'createdBy:id,name']);

            event(new AdministratorUpdated(
                administrator: $administrator,
                actor: $actor,
                before: $before,
                after: $this->snapshot($administrator),
            ));

            return $administrator;
        });
    }

    /**
     * Activate/deactivate an administrator and record the activity.
     */
    public function updateStatus(User $administrator, string $status, User $actor): User
    {
        return $this->transaction(function () use ($administrator, $status, $actor): User {
            $from = $administrator->status;

            $this->setAdministratorStatusAction->handle($administrator, $actor, $status);

            $administrator->load(['roles', 'roles.permissions', 'createdBy:id,name']);

            if ($from !== $administrator->status) {
                event(new AdministratorStatusChanged(
                    administrator: $administrator,
                    actor: $actor,
                    from: $from,
                    to: $administrator->status,
                ));
            }

            return $administrator;
        });
    }

    /**
     * Clamp the requested page size between 1 and 100.
     *
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }

    /**
     * Capture the identity-relevant state of an administrator for audit logs.
     *
     * @return array<string, mixed>
     */
    private function snapshot(User $administrator): array
    {
        return [
            'first_name' => $administrator->first_name,
            'last_name' => $administrator->last_name,
            'name' => $administrator->name,
            'email' => $administrator->email,
            'status' => $administrator->status,
            'roles' => $administrator->getRoleNames()->values()->all(),
        ];
    }
}
