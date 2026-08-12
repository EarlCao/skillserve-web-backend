<?php

namespace App\Modules\ServiceCategories\Actions;

use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: update a subcategory, guaranteeing it still belongs to
 * the parent category in the URL (a subcategory can never be moved to, or
 * edited through, the wrong category).
 */
final class UpdateServiceSubcategoryAction extends BaseAction
{
    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ApiException when the subcategory does not belong to the
     *                      given category (404 — the resource is effectively
     *                      not found under this category).
     */
    public function handle(
        ServiceCategory $category,
        ServiceSubcategory $subcategory,
        array $validated,
    ): ServiceSubcategory {
        $this->assertBelongsToCategory($category, $subcategory);

        $subcategory->fill([
            'name' => $validated['name'] ?? $subcategory->name,
            'description' => array_key_exists('description', $validated)
                ? $validated['description']
                : $subcategory->description,
            'status' => $validated['status'] ?? $subcategory->status,
        ])->save();

        return $subcategory;
    }

    /**
     * @throws ApiException
     */
    private function assertBelongsToCategory(ServiceCategory $category, ServiceSubcategory $subcategory): void
    {
        if ($subcategory->category_id !== $category->id) {
            throw new ApiException('Resource not found.', 404);
        }
    }
}
