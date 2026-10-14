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

    /**
     * A staff reply on the user's own ticket, so it follows the "Messages"
     * switch rather than "Announcements".
     */
    public function notificationCategory(): ?string
    {
        return 'message';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_response',
            'title' => 'Customer Support replied',
            // A reply can be photos alone.
            'message' => $this->message->body !== '' ? $this->message->body : '📷 Photo',
            'body' => $this->message->body !== '' ? $this->message->body : '📷 Photo',
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
        ];
    }
}
