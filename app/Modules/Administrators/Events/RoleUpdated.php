<?php

namespace App\Modules\Administrators\Events;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Dispatched after a role's name/description is updated.
 */
class RoleUpdated
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly Role $role,
        public readonly User $actor,
        public readonly array $before,
        public readonly array $after,
    ) {}
}
