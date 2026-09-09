<?php

namespace App\Modules\ClientCommunication\Notifications;

use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use App\Shared\Notifications\BaseNotification;

class SupportTicketResponseNotification extends BaseNotification
{
    public function __construct(
        private readonly SupportTicket $ticket,
        private readonly SupportTicketMessage $message,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_response',
            'title' => 'New support ticket response',
            'message' => $this->message->body,
            'body' => $this->message->body,
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
        ];
    }
}
