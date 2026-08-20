<?php

namespace App\Modules\Services\Actions;

use App\Models\User;
use App\Modules\Services\Models\Service;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: reject a service.
 */
final class RejectServiceAction extends BaseAction
{
    public function handle(Service $service, User $actor, string $reason): Service
    {
        $service->update([
            'approval_status' => 'rejected',
            'status' => 'draft',
            'rejection_reason' => $reason,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return $service;
    }
}
