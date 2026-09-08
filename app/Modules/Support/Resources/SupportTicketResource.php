<?php

namespace App\Modules\Support\Resources;

use App\Shared\Resources\BaseResource;

class SupportTicketResource extends BaseResource
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
            'requester' => $this->whenLoaded('requester', fn () => $this->person($this->requester)),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->person($this->assignedTo)),
            'assigned_by' => $this->whenLoaded('assignedBy', fn () => $this->person($this->assignedBy)),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'resolved_by' => $this->whenLoaded('resolvedBy', fn () => $this->person($this->resolvedBy)),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
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
            'email' => $user->email,
        ] : null;
    }
}
