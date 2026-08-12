<?php

namespace App\Modules\ServiceCategories\Events;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;

/**
 * Dispatched after a subcategory's details are updated.
 */
class ServiceSubcategoryUpdated
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly ServiceSubcategory $subcategory,
        public readonly User $actor,
        public readonly array $before,
        public readonly array $after,
    ) {}
}
