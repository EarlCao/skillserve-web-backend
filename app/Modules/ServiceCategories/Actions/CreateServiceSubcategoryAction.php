<?php

namespace App\Modules\ServiceCategories\Actions;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: create a subcategory under a parent category.
 */
final class CreateServiceSubcategoryAction extends BaseAction
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(ServiceCategory $category, array $validated, User $actor): ServiceSubcategory
    {
        return ServiceSubcategory::create([
            'category_id' => $category->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'enabled',
        ]);
    }
}
