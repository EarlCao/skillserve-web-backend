<?php

namespace App\Modules\ClientCommunication\Resources;

use App\Shared\Resources\BaseResource;

class BookingMessageResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'content' => $this->content,
            'sender' => $this->whenLoaded('sender', fn () => $this->person($this->sender)),
            'receiver' => $this->whenLoaded('receiver', fn () => $this->person($this->receiver)),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
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
