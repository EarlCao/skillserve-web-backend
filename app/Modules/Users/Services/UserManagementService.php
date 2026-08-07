<?php

namespace App\Modules\Users\Services;

use App\Models\User;
use App\Modules\Users\Actions\ActivateUserAction;
use App\Modules\Users\Actions\BanUserAction;
use App\Modules\Users\Actions\DeleteUserAction;
use App\Modules\Users\Actions\SuspendUserAction;
use App\Modules\Users\Actions\UpdateUserAction;
use App\Modules\Users\Events\UserActivated;
use App\Modules\Users\Events\UserBanned;
use App\Modules\Users\Events\UserDeleted;
use App\Modules\Users\Events\UserSuspended;
use App\Modules\Users\Events\UserUpdated;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Orchestrates user management: listing (search/filter/sort/paginate),
 * profile updates and the moderation lifecycle (suspend / activate / ban /
 * delete). Controllers stay thin.
 *
 * The Users module manages platform users (accounts without an admin role);
 * administrators live in the Administrator Management module.
 */
class UserManagementService extends BaseService
{
    /**
     * Columns that can be sorted on.
     */
    private const SORTABLE = ['name', 'created_at', 'last_login_at'];

    public function __construct(
        private readonly UpdateUserAction $updateUserAction,
        private readonly SuspendUserAction $suspendUserAction,
        private readonly ActivateUserAction $activateUserAction,
        private readonly BanUserAction $banUserAction,
        private readonly DeleteUserAction $deleteUserAction,
    ) {}

    /**
     * Paginated, searchable, filterable, sortable user listing.
     *
     * @param  array{search?: string, user_type?: string, status?: string, verification?: string, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = User::query()
            // User Management = platform users; role-bearing accounts are
            // administrators and are managed by Administrator Management.
            ->doesntHave('roles')
            // roles + permissions are read by the base UserResource — eager
            // loading them avoids an N+1 on every row of the listing.
            ->with(['roles', 'roles.permissions', 'createdBy:id,name']);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(function ($q) use ($term, $search): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]);

                // Numeric searches also match the user ID.
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        if ($userType = trim((string) ($filters['user_type'] ?? ''))) {
            $query->where('user_type', $userType);
        }

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        if (($filters['verification'] ?? null) === 'verified') {
            $query->whereNotNull('email_verified_at');
        } elseif (($filters['verification'] ?? null) === 'unverified') {
            $query->whereNull('email_verified_at');
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters));
    }

    /**
     * Load a single user with its profile relations, moderation actors and
     * recent audit activity.
     */
    public function show(User $user): User
    {
        $this->assertPlatformUser($user);

        return $user->load([
            'roles',
            'roles.permissions',
            'createdBy:id,name',
            'suspendedBy:id,name',
            'activatedBy:id,name',
            'bannedBy:id,name',
            'deletedBy:id,name',
            'activities' => fn ($query) => $query->latest()->limit(20),
        ]);
    }

    /**
     * Update a user's profile and record the activity.
     *
     * @param  array<string, mixed>  $validated
     */
    public function update(User $user, array $validated, User $actor): User
    {
        $this->assertPlatformUser($user);

        return $this->transaction(function () use ($user, $validated, $actor): User {
            $before = $this->snapshot($user);

            $this->updateUserAction->handle($user, $validated);

            $this->loadRelations($user);

            event(new UserUpdated(
                user: $user,
                actor: $actor,
                before: $before,
                after: $this->snapshot($user),
            ));

            return $user;
        });
    }

    /**
     * Temporarily suspend a user and record the activity.
     */
    public function suspend(User $user, string $reason, User $actor): User
    {
        $this->assertPlatformUser($user);

        return $this->transaction(function () use ($user, $reason, $actor): User {
            $this->suspendUserAction->handle($user, $actor, $reason);

            $this->loadRelations($user);

            event(new UserSuspended(user: $user, actor: $actor, reason: $reason));

            return $user;
        });
    }

    /**
     * Restore a suspended user and record the activity.
     */
    public function activate(User $user, User $actor): User
    {
        $this->assertPlatformUser($user);

        return $this->transaction(function () use ($user, $actor): User {
            $from = $user->status;

            $this->activateUserAction->handle($user, $actor);

            $this->loadRelations($user);

            if ($from !== $user->status) {
                event(new UserActivated(user: $user, actor: $actor));
            }

            return $user;
        });
    }

    /**
     * Permanently ban a user and record the activity.
     */
    public function ban(User $user, string $reason, User $actor): User
    {
        $this->assertPlatformUser($user);

        return $this->transaction(function () use ($user, $reason, $actor): User {
            $this->banUserAction->handle($user, $actor, $reason);

            $this->loadRelations($user);

            event(new UserBanned(user: $user, actor: $actor, reason: $reason));

            return $user;
        });
    }

    /**
     * Soft-delete a user and record the activity.
     */
    public function destroy(User $user, User $actor): void
    {
        $this->assertPlatformUser($user);

        $this->transaction(function () use ($user, $actor): void {
            $this->deleteUserAction->handle($user, $actor);

            event(new UserDeleted(user: $user, actor: $actor));
        });
    }

    /**
     * The Users module manages platform users only — administrator accounts
     * (role-bearing) are owned by the Administrator Management module.
     *
     * @throws ApiException when the account is an administrator.
     */
    private function assertPlatformUser(User $user): void
    {
        if ($user->roles()->exists()) {
            throw new ApiException(
                'Administrator accounts are managed in Administrator Management.',
                422,
                errors: ['id' => ['Administrator accounts cannot be managed here.']],
            );
        }
    }

    /**
     * Eager-load the relations the resource surfaces.
     */
    private function loadRelations(User $user): User
    {
        return $user->load([
            'roles',
            'roles.permissions',
            'createdBy:id,name',
            'suspendedBy:id,name',
            'activatedBy:id,name',
            'bannedBy:id,name',
            'deletedBy:id,name',
        ]);
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
     * Capture the identity-relevant state of a user for audit logs.
     *
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->address,
            'birthday' => $user->birthday?->toDateString(),
            'user_type' => $user->user_type,
            'status' => $user->status,
        ];
    }
}
