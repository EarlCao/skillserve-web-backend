<?php

namespace App\Modules\ServiceCategories\Actions;

use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: update a service category's details.
 *
 * Only the fields actually present in the request are applied (the form
 * request uses `sometimes`), so a partial PATCH cannot wipe other fields.
 */
final class UpdateServiceCategoryAction extends BaseAction
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(ServiceCategory $category, array $validated): ServiceCategory
    {
        $category->fill([
            'name' => $validated['name'] ?? $category->name,
            'description' => array_key_exists('description', $validated)
                ? $validated['description']
                : $category->description,
            'status' => $validated['status'] ?? $category->status,
        ])->save();

        return $category;
    }
}
