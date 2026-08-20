<?php

namespace App\Modules\Services\Actions;

use App\Models\User;
use App\Modules\Services\Models\Service;
use App\Shared\Actions\BaseAction;

/**
 * Single unit of work: approve a service.
 */
final class ApproveServiceAction extends BaseAction
{
    public function handle(Service $service, User $actor, ?string $notes = null): Service
    {
        $service->update([
            'approval_status' => 'approved',
            'status' => 'published',
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        return $service;
    }
}
