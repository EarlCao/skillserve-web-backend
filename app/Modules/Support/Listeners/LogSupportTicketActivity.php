<?php

namespace App\Modules\Support\Listeners;

use App\Modules\Support\Events\SupportTicketAssigned;
use App\Modules\Support\Events\SupportTicketResolved;
use App\Modules\Support\Events\SupportTicketResponseAdded;

class LogSupportTicketActivity
{
    public function handle(SupportTicketAssigned|SupportTicketResponseAdded|SupportTicketResolved $event): void
    {
        if ($event instanceof SupportTicketAssigned) {
            activity('support')
                ->causedBy($event->actor)
                ->performedOn($event->ticket)
                ->withProperties(['assigned_to' => $event->assignee?->id])
                ->log('support_ticket_assigned');

            return;
        }

        if ($event instanceof SupportTicketResponseAdded) {
            activity('support')
                ->causedBy($event->actor)
                ->performedOn($event->ticket)
                ->withProperties(['message_id' => $event->message->id])
                ->log('support_ticket_response_added');

            return;
        }

        activity('support')
            ->causedBy($event->actor)
            ->performedOn($event->ticket)
            ->withProperties(['resolution_note' => $event->resolutionNote])
            ->log('support_ticket_resolved');
    }
}
