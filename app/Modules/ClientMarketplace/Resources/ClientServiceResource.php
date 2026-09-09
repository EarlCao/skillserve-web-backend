<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Shared\Resources\BaseResource;

class ClientServiceResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'price_type' => $this->price_type,
            'currency' => $this->currency,
            'duration' => $this->duration,
            'location' => $this->location,
            'average_rating' => $this->average_rating,
            'total_reviews' => $this->total_reviews,
            'total_bookings' => $this->total_bookings,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'subcategory' => $this->whenLoaded('subcategory', fn () => $this->subcategory ? [
                'id' => $this->subcategory->id,
                'name' => $this->subcategory->name,
            ] : null),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'business_name' => $this->provider->business_name,
                'average_rating' => $this->provider->average_rating,
                'total_reviews' => $this->provider->total_reviews,
            ] : null),
            'reviews' => $this->whenLoaded(
                'reviews',
                fn () => ClientReviewResource::collection($this->reviews),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
