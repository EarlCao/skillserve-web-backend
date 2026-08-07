<?php

namespace App\Modules\Administrators\Events;

use App\Models\User;

/**
 * Dispatched after a new administrator account is created.
 */
class AdministratorCreated
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly User $administrator,
        public readonly User $actor,
        public readonly array $data,
    ) {}
}
