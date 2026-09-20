<?php

namespace App\Modules\ClientCommunication\Notifications;

use App\Modules\Support\Models\SupportTicket;
use App\Shared\Notifications\BaseNotification;

class SupportTicketResolvedNotification extends BaseNotification
{
    public function __construct(
        private readonly SupportTicket $ticket,
        private readonly string $resolutionNote,
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
            'type' => 'support_ticket_resolved',
            'title' => 'Support ticket resolved',
            'message' => $this->resolutionNote,
            'body' => $this->resolutionNote,
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
        ];
    }
}
