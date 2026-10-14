<?php

namespace App\Modules\Notifications\Resources;

use App\Shared\Resources\BaseResource;
use Illuminate\Support\Arr;

/** One admin Inbox alert. */
class AdminAlertResource extends BaseResource
{
    public function toArray($request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'kind' => $data['kind'] ?? null,
            'title' => $data['title'] ?? null,
            'message' => $data['message'] ?? null,
            'link' => $data['link'] ?? null,
            'subject' => Arr::only($data, [
                'provider_id', 'identity_verification_id', 'service_id', 'report_id', 'booking_id', 'ticket_id', 'remittance_id',
            ]),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
