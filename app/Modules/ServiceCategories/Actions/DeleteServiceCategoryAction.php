<?php

namespace App\Modules\ServiceCategories\Actions;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: soft-delete a service category.
 *
 * Guard: a category that still has subcategories cannot be deleted — the
 * administrator must remove them first so no category is ever left half
 * managed. This is a restriction-style deletion strategy (the project never
 * hard-deletes records, and never silently removes dependent data).
 */
final class DeleteServiceCategoryAction extends BaseAction
{
    /**
     * @throws ApiException when the category still has subcategories or is
     *                      already deleted.
     */
    public function handle(ServiceCategory $category, User $actor): ServiceCategory
    {
        if ($category->trashed()) {
            throw new ApiException(
                'The category is already deleted.',
                422,
                errors: ['id' => ['The category is already deleted.']],
            );
        }

        // Non-trashed subcategories block deletion; trashed ones are already
        // gone and cannot be restored through this category anyway.
        if ($category->subcategories()->withoutTrashed()->exists()) {
            throw new ApiException(
                'This category still has subcategories. Delete its subcategories first.',
                422,
                errors: ['subcategories' => ['This category still has subcategories. Delete its subcategories first.']],
            );
        }

        $category->update(['deleted_by' => $actor->id]);
        $category->delete();

        return $category;
    }
}
