<?php

namespace App\Modules\Reviews\Resources;

use App\Shared\Resources\BaseResource;

class ReviewResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'status' => $this->status,
            'is_reported' => $this->is_reported,
            'report_reason' => $this->report_reason,
            'hidden_at' => $this->hidden_at?->toIso8601String(),
            'removed_at' => $this->removed_at?->toIso8601String(),
            'booking' => $this->whenLoaded('booking', fn () => [
                'id' => $this->booking->id,
                'booking_number' => $this->booking->booking_number,
            ]),
            'reviewer' => $this->whenLoaded('reviewer', fn () => [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
                'email' => $this->reviewer->email,
            ]),
            'provider' => $this->whenLoaded('provider', fn () => [
                'id' => $this->provider->id,
                'business_name' => $this->provider->business_name,
                'user' => $this->provider->user ? [
                    'id' => $this->provider->user->id,
                    'name' => $this->provider->user->name,
                    'email' => $this->provider->user->email,
                ] : null,
            ]),
            'service' => $this->whenLoaded('service', fn () => [
                'id' => $this->service->id,
                'title' => $this->service->title,
            ]),
            'hidden_by' => $this->whenLoaded('hiddenBy', fn () => $this->hiddenBy ? [
                'id' => $this->hiddenBy->id,
                'name' => $this->hiddenBy->name,
            ] : null),
            'removed_by' => $this->whenLoaded('removedBy', fn () => $this->removedBy ? [
                'id' => $this->removedBy->id,
                'name' => $this->removedBy->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
