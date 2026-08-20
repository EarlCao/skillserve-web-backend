<?php

namespace App\Modules\Services\Events;

use App\Models\User;
use App\Modules\Services\Models\Service;

/**
 * Dispatched after a service is approved.
 */
class ServiceApproved
{
    public function __construct(
        public readonly Service $service,
        public readonly User $actor,
        public readonly ?string $notes = null,
    ) {}
}
