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
            'skills' => $this->skills,
            'certifications' => $this->certifications,
            'languages' => $this->languages,
            'average_rating' => $this->average_rating,
            'total_reviews' => $this->total_reviews,
            'total_bookings' => $this->total_bookings,
            'completed_bookings' => $this->completed_bookings,
            'is_featured' => (bool) $this->is_featured,
            'is_accepting_bookings' => (bool) $this->is_accepting_bookings,
            // Published weekly hours, on the detail response only. Empty
            // means the provider publishes none, which does not restrict
            // when they can be booked.
            'availability' => $this->whenLoaded(
                'availabilities',
                fn () => ProviderAvailabilityResource::collection($this->availabilities),
            ),
            // The public catalog only lists verified providers, but the app
            // shows the verification state explicitly rather than implying it.
            'verification_status' => $this->verification_status,
            'verified_at' => $this->verified_at?->toIso8601String(),
            // Work samples and recognition badges, loaded on the detail
            // endpoint only — the list would issue a query per provider.
            'portfolio' => $this->whenLoaded(
                'portfolioItems',
                fn () => ClientPortfolioItemResource::collection($this->portfolioItems),
            ),
            'badges' => $this->whenLoaded(
                'badges',
                fn () => ClientBadgeResource::collection($this->badges),
            ),
            // Catalog summary, present on the public provider list and detail.
            'starting_price' => $this->when(
                array_key_exists('starting_price', $this->resource->getAttributes()),
                fn () => $this->starting_price === null ? null : number_format((float) $this->starting_price, 2, '.', ''),
            ),
            'primary_category' => $this->when(
                array_key_exists('primary_category', $this->resource->getAttributes()),
                fn () => $this->primary_category,
            ),
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
