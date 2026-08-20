<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Models\Service;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: hide/unhide a service from public visibility.
 */
final class HideServiceAction extends BaseAction
{
    public function handle(Service $service, bool $isHidden): Service
    {
        $service->update([
            'is_hidden' => $isHidden,
        ]);

        return $service;
    }
}
