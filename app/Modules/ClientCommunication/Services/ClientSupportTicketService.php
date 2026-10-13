<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\ClientCommunication\Actions\CreateSupportTicketAction;
use App\Modules\Support\Actions\AddSupportTicketResponseAction;
use App\Modules\Support\Events\SupportTicketResponseAdded;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketAttachment;
use App\Shared\Helpers\PageSize;
use App\Shared\Helpers\PrivateImages;
use App\Shared\Services\BaseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Raises a ticket, with any [images] attached to it.
     *
     * @param  array<int, UploadedFile>  $images
     */
    public function create(User $client, array $data, array $images = []): SupportTicket
    {
        $stored = PrivateImages::store($images, SupportTicketAttachment::DISK, (string) $client->id);

        try {
            return $this->transaction(function () use ($client, $data, $stored): SupportTicket {
                $ticket = $this->createAction->handle($client, $data);
                $this->attach($ticket, null, $client, $stored);

                return $ticket->load($this->relations(true));
            });
        } catch (\Throwable $exception) {
            PrivateImages::delete(SupportTicketAttachment::DISK, $stored);

            throw $exception;
        }
    }

    public function show(User $client, SupportTicket $ticket): SupportTicket
    {
        return $this->ownedTicket($client, $ticket)->load($this->relations(true));
    }

    /**
     * Adds the requester's reply: text, [images], or both.
     *
     * @param  array<int, UploadedFile>  $images
     */
    public function reply(User $client, SupportTicket $ticket, ?string $body, array $images = []): SupportTicket
    {
        $ticket = $this->ownedTicket($client, $ticket);
        $stored = PrivateImages::store($images, SupportTicketAttachment::DISK, (string) $client->id);

        try {
            return $this->transaction(function () use ($client, $ticket, $body, $stored): SupportTicket {
                $message = $this->responseAction->handle($ticket, $client, (string) $body);
                $this->attach($ticket, $message->id, $client, $stored);
                $ticket = $ticket->fresh($this->relations(true));
                event(new SupportTicketResponseAdded($ticket, $message, $client));

                return $ticket;
            });
        } catch (\Throwable $exception) {
            PrivateImages::delete(SupportTicketAttachment::DISK, $stored);

            throw $exception;
        }
    }

    /** One of the photos on the requester's own ticket. */
    public function attachment(User $client, SupportTicket $ticket, SupportTicketAttachment $attachment): StreamedResponse
    {
        $ticket = $this->ownedTicket($client, $ticket);
        abort_unless((int) $attachment->support_ticket_id === (int) $ticket->id, 404);

        return PrivateImages::stream(SupportTicketAttachment::DISK, $attachment->path);
    }

    /** @param  array<int, array{path: string, mime_type: string, size_bytes: int}>  $stored */
    private function attach(SupportTicket $ticket, ?int $messageId, User $uploader, array $stored): void
    {
        foreach ($stored as $photo) {
            $ticket->attachments()->create([
                ...$photo,
                'support_ticket_message_id' => $messageId,
                'uploaded_by' => $uploader->id,
            ]);
        }
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
            ? ['requester:id,name,role_id', 'messages.author:id,name', 'attachments']
            : ['requester:id,name,role_id'];
    }

    private function perPage(array $filters): int
    {
        return PageSize::from($filters);
    }
}
