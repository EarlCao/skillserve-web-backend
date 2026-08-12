<?php

namespace App\Modules\ServiceCategories\Actions;

use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: enable or disable a service category.
 *
 * Disabled categories stay in the database (never physically deleted) but
 * become unavailable for selection/display on the platform (Service
 * Categories 6.6).
 */
final class SetServiceCategoryStatusAction extends BaseAction
{
    public function handle(ServiceCategory $category, string $status): ServiceCategory
    {
        if ($category->status !== $status) {
            $category->update(['status' => $status]);
        }

        return $category;
    }
}
