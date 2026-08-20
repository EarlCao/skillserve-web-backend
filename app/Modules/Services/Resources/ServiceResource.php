<?php

namespace App\Modules\Services\Resources;

use App\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Shapes a service for the management screens.
 *
 * - The list endpoint includes category, subcategory, and provider names.
 * - The detail endpoint includes full relationships.
 * Requires the related data to be loaded to avoid N+1 queries — the service
 * eager-loads it.
 */
class ServiceResource extends BaseResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
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
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'rejection_reason' => $this->rejection_reason,
            'is_featured' => $this->is_featured,
            'is_hidden' => $this->is_hidden,
            'total_bookings' => $this->total_bookings,
            'completed_bookings' => $this->completed_bookings,
            'average_rating' => $this->average_rating,
            'total_reviews' => $this->total_reviews,
            'provider' => $this->whenLoaded('provider', fn () => [
                'id' => $this->provider->id,
                'business_name' => $this->provider->business_name,
                'user' => $this->provider->user ? [
                    'id' => $this->provider->user->id,
                    'name' => $this->provider->user->name,
                    'email' => $this->provider->user->email,
                ] : null,
            ]),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'subcategory' => $this->whenLoaded('subcategory', fn () => [
                'id' => $this->subcategory->id,
                'name' => $this->subcategory->name,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->name,
            ] : null),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy ? [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ] : null),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
