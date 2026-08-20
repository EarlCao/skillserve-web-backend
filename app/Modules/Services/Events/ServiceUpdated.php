<?php

namespace App\Modules\Services\Events;

use App\Models\User;
use App\Modules\Services\Models\Service;

/**
 * Dispatched after a service is updated.
 */
class ServiceUpdated
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly Service $service,
        public readonly User $actor,
        public readonly array $before,
        public readonly array $after,
    ) {}
}
