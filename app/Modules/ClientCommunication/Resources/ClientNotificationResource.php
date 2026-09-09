<?php

namespace App\Modules\ClientCommunication\Resources;

use App\Shared\Resources\BaseResource;
use Illuminate\Support\Arr;

class ClientNotificationResource extends BaseResource
{
    public function toArray($request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'type' => $data['type'] ?? $this->type,
            'title' => $data['title'] ?? null,
            'message' => $data['message'] ?? ($data['body'] ?? null),
            'data' => Arr::only($data, [
                'type', 'title', 'message', 'body', 'announcement_id', 'booking_id', 'ticket_id',
                'ticket_number',
            ]),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
