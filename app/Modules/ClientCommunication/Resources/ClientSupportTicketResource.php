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
            // Photos sent with the ticket itself; each reply carries its own.
            'attachments' => $this->whenLoaded('attachments', fn () => $this->photos(null)),
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'resolution_note' => $this->resolution_note,
            'requester' => $this->whenLoaded('requester', fn () => $this->person($this->requester)),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($message) => [
                'id' => $message->id,
                'body' => $message->body,
                'attachments' => $this->relationLoaded('attachments') ? $this->photos($message->id) : [],
                'author' => $message->relationLoaded('author') ? $this->person($message->author) : null,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** The requester's photos, read with their token. */
    private function photos(?int $messageId): array
    {
        return $this->attachmentsOf($messageId)->map(fn ($attachment): array => [
            'id' => $attachment->id,
            'url' => url("/api/client/v1/support/tickets/{$this->id}/attachments/{$attachment->id}"),
        ])->all();
    }

    private function person(mixed $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $user->name,
        ] : null;
    }
}
