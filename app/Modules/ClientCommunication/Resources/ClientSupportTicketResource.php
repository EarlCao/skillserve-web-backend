<?php

namespace App\Modules\ClientCommunication\Resources;

use App\Shared\Resources\BaseResource;

class ClientSupportTicketResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'subject' => $this->subject,
            'description' => $this->description,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'resolution_note' => $this->resolution_note,
            'requester' => $this->whenLoaded('requester', fn () => $this->person($this->requester)),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($message) => [
                'id' => $message->id,
                'body' => $message->body,
                'author' => $message->relationLoaded('author') ? $this->person($message->author) : null,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function person(mixed $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $user->name,
        ] : null;
    }
}
