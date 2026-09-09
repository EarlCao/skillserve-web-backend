<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Shared\Resources\BaseResource;

class ClientProviderResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'bio' => $this->bio,
            'specialization' => $this->specialization,
            'experience_years' => $this->experience_years,
            'hourly_rate' => $this->hourly_rate,
            'location' => $this->location,
            'website' => $this->website,
            'social_links' => $this->social_links,
            'portfolio' => $this->portfolio,
            'skills' => $this->skills,
            'certifications' => $this->certifications,
            'languages' => $this->languages,
            'average_rating' => $this->average_rating,
            'total_reviews' => $this->total_reviews,
            'total_bookings' => $this->total_bookings,
            'completed_bookings' => $this->completed_bookings,
            'services' => $this->whenLoaded(
                'services',
                fn () => ClientServiceResource::collection($this->services),
            ),
            'reviews' => $this->whenLoaded(
                'reviews',
                fn () => ClientReviewResource::collection($this->reviews),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
