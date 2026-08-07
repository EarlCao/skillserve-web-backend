<?php

namespace App\Modules\Administrators\Events;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Dispatched after a new role is created.
 */
class RoleCreated
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly Role $role,
        public readonly User $actor,
        public readonly array $data,
    ) {}
}
