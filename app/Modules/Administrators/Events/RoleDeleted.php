<?php

namespace App\Modules\Administrators\Events;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Dispatched after a role is deleted. The role name is captured because the
 * model no longer exists in the database by the time the log is written.
 */
class RoleDeleted
{
    public function __construct(
        public readonly Role $role,
        public readonly User $actor,
        public readonly string $name,
    ) {}
}
