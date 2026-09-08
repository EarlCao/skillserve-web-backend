<?php

namespace App\Modules\Support\Events;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;

class SupportTicketAssigned
{
    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly User $actor,
        public readonly ?User $assignee,
    ) {}
}
