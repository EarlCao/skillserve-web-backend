<?php

namespace App\Modules\ServiceCategories\Events;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;

/**
 * Dispatched after a subcategory is soft-deleted.
 */
class ServiceSubcategoryDeleted
{
    public function __construct(
        public readonly ServiceSubcategory $subcategory,
        public readonly User $actor,
    ) {}
}
