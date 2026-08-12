<?php

namespace App\Modules\ServiceCategories\Events;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;

/**
 * Dispatched after a subcategory is created under a category.
 */
class ServiceSubcategoryCreated
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly ServiceSubcategory $subcategory,
        public readonly User $actor,
        public readonly array $data,
    ) {}
}
