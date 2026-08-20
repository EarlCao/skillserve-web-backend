<?php

namespace App\Modules\Services\Actions;

use App\Models\User;
use App\Modules\Services\Models\Service;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: soft-delete a service.
 */
final class DeleteServiceAction extends BaseAction
{
    public function handle(Service $service, User $actor): void
    {
        $service->update([
            'deleted_by' => $actor->id,
        ]);

        $service->delete();
    }
}
