<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Models\Service;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: update a service's details.
 */
final class UpdateServiceAction extends BaseAction
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(Service $service, array $validated): Service
    {
        if (
            array_key_exists('category_id', $validated)
            && (int) $validated['category_id'] !== (int) $service->category_id
            && ! array_key_exists('subcategory_id', $validated)
        ) {
            $validated['subcategory_id'] = null;
        }

        $service->update($validated);

        return $service;
    }
}
