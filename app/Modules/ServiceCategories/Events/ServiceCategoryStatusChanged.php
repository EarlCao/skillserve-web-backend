<?php

namespace App\Modules\ServiceCategories\Events;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceCategory;

/**
 * Dispatched after a service category is enabled or disabled.
 */
class ServiceCategoryStatusChanged
{
    public function __construct(
        public readonly ServiceCategory $category,
        public readonly User $actor,
        public readonly string $from,
        public readonly string $to,
    ) {}
}
