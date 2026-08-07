<?php

namespace App\Modules\Users\Events;

use App\Models\User;

/**
 * Dispatched after a platform user's profile is updated.
 */
class UserUpdated
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly User $user,
        public readonly User $actor,
        public readonly array $before,
        public readonly array $after,
    ) {}
}
