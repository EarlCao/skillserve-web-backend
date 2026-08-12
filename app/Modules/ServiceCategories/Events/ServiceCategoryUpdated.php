<?php

namespace App\Modules\ServiceCategories\Events;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceCategory;

/**
 * Dispatched after a service category's details are updated.
 */
class ServiceCategoryUpdated
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly ServiceCategory $category,
        public readonly User $actor,
        public readonly array $before,
        public readonly array $after,
    ) {}
}
