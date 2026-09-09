<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Shared\Resources\BaseResource;

class ClientReviewResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'status' => $this->status,
            'booking' => $this->whenLoaded('booking', fn () => [
                'id' => $this->booking->id,
                'booking_number' => $this->booking->booking_number,
            ]),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'business_name' => $this->provider->business_name,
            ] : null),
            'service' => $this->whenLoaded('service', fn () => $this->service ? [
                'id' => $this->service->id,
                'title' => $this->service->title,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
