<?php

namespace App\Modules\ServiceCategories\Actions;

use App\Models\User;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: create a new service category.
 */
final class CreateServiceCategoryAction extends BaseAction
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(array $validated, User $actor): ServiceCategory
    {
        return ServiceCategory::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'enabled',
            'created_by' => $actor->id,
        ]);
    }
}
