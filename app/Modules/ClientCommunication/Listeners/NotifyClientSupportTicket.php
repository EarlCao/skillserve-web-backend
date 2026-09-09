<?php

namespace App\Modules\ClientCommunication\Listeners;

use App\Modules\ClientCommunication\Notifications\SupportTicketResolvedNotification;
use App\Modules\ClientCommunication\Notifications\SupportTicketResponseNotification;
use App\Modules\Support\Events\SupportTicketResolved;
use App\Modules\Support\Events\SupportTicketResponseAdded;

class NotifyClientSupportTicket
{
    public function handle(SupportTicketResponseAdded|SupportTicketResolved $event): void
    {
        $client = $event->ticket->requester;

        if (! $client || $client->user_type !== 'customer' || $client->id === $event->actor->id) {
            return;
        }

        $notification = $event instanceof SupportTicketResponseAdded
            ? new SupportTicketResponseNotification($event->ticket, $event->message)
            : new SupportTicketResolvedNotification($event->ticket, $event->resolutionNote);

        $client->notify($notification);
    }
}
