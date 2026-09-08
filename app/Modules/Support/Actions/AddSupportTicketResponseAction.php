<?php

namespace App\Modules\Support\Actions;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

final class AddSupportTicketResponseAction extends BaseAction
{
    public function handle(SupportTicket $ticket, User $actor, string $body): SupportTicketMessage
    {
        if ($ticket->isResolved()) {
            throw new ApiException('Resolved support tickets cannot receive additional responses.', 422);
        }

        $message = $ticket->messages()->create([
            'author_id' => $actor->id,
            'body' => trim($body),
        ]);

        if ($ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        }

        return $message->load('author:id,name,email');
    }
}
