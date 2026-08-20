<?php

namespace App\Modules\Services\Actions;

use App\Models\User;
use App\Modules\Services\Models\Service;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: create a new service.
 */
final class CreateServiceAction extends BaseAction
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(array $validated, User $actor): Service
    {
        return Service::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'category_id' => $validated['category_id'],
            'subcategory_id' => $validated['subcategory_id'] ?? null,
            'price' => $validated['price'] ?? null,
            'price_type' => $validated['price_type'] ?? 'fixed',
            'currency' => $validated['currency'] ?? 'USD',
            'duration' => $validated['duration'] ?? null,
            'location' => $validated['location'] ?? null,
            'status' => 'draft',
            'approval_status' => 'pending',
            'created_by' => $actor->id,
        ]);
    }
}
