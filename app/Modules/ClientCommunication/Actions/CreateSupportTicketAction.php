<?php

namespace App\Modules\ClientCommunication\Actions;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;
use App\Shared\Actions\BaseAction;
use Illuminate\Support\Str;

final class CreateSupportTicketAction extends BaseAction
{
    public function handle(User $requester, array $data): SupportTicket
    {
        return SupportTicket::query()->create([
            'ticket_number' => 'SUP-'.Str::upper(Str::random(12)),
            'requester_id' => $requester->id,
            'subject' => trim($data['subject']),
            'description' => trim($data['description']),
            'category' => trim((string) ($data['category'] ?? 'general')) ?: 'general',
            'priority' => 'normal',
            'status' => 'open',
        ]);
    }
}
