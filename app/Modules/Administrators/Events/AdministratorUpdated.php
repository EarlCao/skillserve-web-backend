<?php

namespace App\Modules\Administrators\Events;

use App\Models\User;

/**
 * Dispatched after an administrator's profile is updated.
 */
class AdministratorUpdated
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly User $administrator,
        public readonly User $actor,
        public readonly array $before,
        public readonly array $after,
    ) {}
}
