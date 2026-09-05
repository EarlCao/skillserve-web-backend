<?php

namespace App\Modules\ProviderRecognition\Resources;

use App\Shared\Resources\BaseResource;

class RecognitionProviderResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'verification_status' => $this->verification_status,
            'is_featured' => (bool) $this->is_featured,
            'average_rating' => $this->average_rating,
            'total_reviews' => (int) $this->total_reviews,
            'total_bookings' => (int) $this->total_bookings,
            'completed_bookings' => (int) $this->completed_bookings,
            'badges' => $this->whenLoaded('badges', fn () => ProviderBadgeResource::collection($this->badges)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
