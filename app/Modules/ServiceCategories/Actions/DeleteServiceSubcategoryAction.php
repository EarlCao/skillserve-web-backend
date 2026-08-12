<?php

namespace App\Modules\ServiceCategories\Actions;

use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: soft-delete a subcategory, guaranteeing it belongs to
 * the parent category in the URL.
 */
final class DeleteServiceSubcategoryAction extends BaseAction
{
    /**
     * @throws ApiException when the subcategory does not belong to the given
     *                      category or is already deleted.
     */
    public function handle(
        ServiceCategory $category,
        ServiceSubcategory $subcategory,
    ): ServiceSubcategory {
        if ($subcategory->category_id !== $category->id) {
            throw new ApiException('Resource not found.', 404);
        }

        if ($subcategory->trashed()) {
            throw new ApiException(
                'The subcategory is already deleted.',
                422,
                errors: ['id' => ['The subcategory is already deleted.']],
            );
        }

        $subcategory->delete();

        return $subcategory;
    }
}
