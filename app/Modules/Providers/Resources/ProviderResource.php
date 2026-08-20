<?php

namespace App\Modules\Providers\Resources;

use App\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Shapes a ProviderProfile into the standard API envelope data format.
 */
class ProviderResource extends BaseResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'created_at' => $this->user->created_at?->toIso8601String(),
            ],
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
            'verification_status' => $this->verification_status,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifiedBy', function () {
                return [
                    'id' => $this->verifiedBy->id,
                    'name' => $this->verifiedBy->name,
                ];
            }),
            'rejection_reason' => $this->rejection_reason,
            'is_suspended' => $this->isSuspended(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspended_by' => $this->whenLoaded('suspendedBy', function () {
                return [
                    'id' => $this->suspendedBy->id,
                    'name' => $this->suspendedBy->name,
                ];
            }),
            'suspension_reason' => $this->suspension_reason,
            'latest_verification_request' => new VerificationRequestResource($this->whenLoaded('latestVerificationRequest')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
