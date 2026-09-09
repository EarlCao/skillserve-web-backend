<?php

namespace App\Modules\Support\Actions;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

final class ResolveSupportTicketAction extends BaseAction
{
    public function handle(SupportTicket $ticket, User $actor, string $resolutionNote): SupportTicket
    {
        if ($ticket->isResolved()) {
            throw new ApiException('This support ticket is already resolved.', 422);
        }

        $ticket->update([
            'status' => 'resolved',
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
            'resolution_note' => trim($resolutionNote),
        ]);

        return $ticket->fresh(['requester:id,name,email,user_type', 'assignedTo:id,name,email', 'assignedBy:id,name,email', 'resolvedBy:id,name,email', 'messages.author:id,name,email']);
    }
}
