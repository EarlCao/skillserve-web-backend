<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Models\Service;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: feature/unfeature a service.
 */
final class FeatureServiceAction extends BaseAction
{
    public function handle(Service $service, bool $isFeatured): Service
    {
        $service->update([
            'is_featured' => $isFeatured,
        ]);

        return $service;
    }
}
