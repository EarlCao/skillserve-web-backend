<?php

namespace App\Modules\ClientAuthentication\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'birthday' => $this->birthday?->toDateString(),
            'status' => $this->status,
            'user_type' => $this->user_type,
            'provider' => $this->when(
                $this->user_type === 'provider' && $this->providerProfile()->exists(),
                fn () => [
                    'id' => $this->providerProfile->id,
                    'business_name' => $this->providerProfile->business_name,
                    'specialization' => $this->providerProfile->specialization,
                    'experience_years' => $this->providerProfile->experience_years,
                    'bio' => $this->providerProfile->bio,
                    'verification_status' => $this->providerProfile->verification_status,
                ],
            ),
            'email_verified' => $this->hasVerifiedEmail(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
