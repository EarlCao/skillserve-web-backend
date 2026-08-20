<?php

namespace App\Modules\Services\Events;

use App\Models\User;
use App\Modules\Services\Models\Service;

/**
 * Dispatched after a service is hidden/unhidden.
 */
class ServiceHidden
{
    public function __construct(
        public readonly Service $service,
        public readonly User $actor,
        public readonly bool $isHidden,
    ) {}
}
