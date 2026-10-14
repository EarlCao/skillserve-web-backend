<?php

namespace App\Modules\Support\Events;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;

/** A user raised a support ticket from the app. */
class SupportTicketCreated
{
    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly User $actor,
    ) {}
}
