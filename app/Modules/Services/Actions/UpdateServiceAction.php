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
        $service->update($validated);

        return $service;
    }
}
