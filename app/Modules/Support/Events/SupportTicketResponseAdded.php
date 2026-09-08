<?php

namespace App\Modules\Support\Events;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;

class SupportTicketResponseAdded
{
    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly SupportTicketMessage $message,
        public readonly User $actor,
    ) {}
}
