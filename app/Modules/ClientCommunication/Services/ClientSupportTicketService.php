<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\ClientCommunication\Actions\CreateSupportTicketAction;
use App\Modules\Support\Actions\AddSupportTicketResponseAction;
use App\Modules\Support\Events\SupportTicketResponseAdded;
use App\Modules\Support\Models\SupportTicket;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

class ClientSupportTicketService extends BaseService
{
    public function __construct(
        private readonly CreateSupportTicketAction $createAction,
        private readonly AddSupportTicketResponseAction $responseAction,
    ) {}

    public function index(User $client, array $filters): LengthAwarePaginator
    {
        $query = SupportTicket::query()
            ->where('requester_id', $client->id)
            ->with($this->relations(false));

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query
            ->latest()
            ->paginate($this->perPage($filters));
    }

    public function create(User $client, array $data): SupportTicket
    {
        return $this->transaction(function () use ($client, $data): SupportTicket {
            return $this->createAction
                ->handle($client, $data)
                ->load($this->relations(true));
        });
    }

    public function show(User $client, SupportTicket $ticket): SupportTicket
    {
        return $this->ownedTicket($client, $ticket)->load($this->relations(true));
    }

    public function reply(User $client, SupportTicket $ticket, string $body): SupportTicket
    {
        return $this->transaction(function () use ($client, $ticket, $body): SupportTicket {
            $ticket = $this->ownedTicket($client, $ticket);
            $message = $this->responseAction->handle($ticket, $client, $body);
            $ticket = $ticket->fresh($this->relations(true));
            event(new SupportTicketResponseAdded($ticket, $message, $client));

            return $ticket;
        });
    }

    private function ownedTicket(User $client, SupportTicket $ticket): SupportTicket
    {
        return SupportTicket::query()
            ->whereKey($ticket->id)
            ->where('requester_id', $client->id)
            ->firstOrFail();
    }

    private function relations(bool $withMessages): array
    {
        return $withMessages
            ? ['requester:id,name,user_type', 'messages.author:id,name']
            : ['requester:id,name,user_type'];
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 15)));
    }
}
