<?php

namespace App\Modules\ServiceCategories\Events;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceCategory;

/**
 * Dispatched after a service category is created.
 */
class ServiceCategoryCreated
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly ServiceCategory $category,
        public readonly User $actor,
        public readonly array $data,
    ) {}
}
