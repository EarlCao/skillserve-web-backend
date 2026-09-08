<?php

namespace App\Modules\Support\Actions;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

final class AssignSupportTicketAction extends BaseAction
{
    public function handle(SupportTicket $ticket, ?User $assignee, User $actor): SupportTicket
    {
        if ($assignee !== null && (! $assignee->isActive() || ! $assignee->roles()->exists())) {
            throw new ApiException(
                'Support tickets can only be assigned to active administrators.',
                422,
                errors: ['assigned_to' => ['Select an active administrator.']],
            );
        }

        $ticket->update([
            'assigned_to' => $assignee?->id,
            'assigned_by' => $assignee ? $actor->id : null,
            'assigned_at' => $assignee ? now() : null,
        ]);

        return $ticket->fresh(['requester:id,name,email', 'assignedTo:id,name,email', 'assignedBy:id,name,email', 'resolvedBy:id,name,email', 'messages.author:id,name,email']);
    }
}
