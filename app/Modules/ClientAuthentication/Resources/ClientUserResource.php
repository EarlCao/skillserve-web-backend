<?php

namespace App\Modules\ClientAuthentication\Resources;

use App\Modules\ClientAuthentication\Services\ClientProfileService;
use App\Shared\Enums\AccountRole;
use App\Shared\Exceptions\AccountRestrictedException;
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
            // Absolute URL derived from the stored path, so the app can
            // load it directly without knowing where photos are kept.
            'profile_picture' => ClientProfileService::photoUrl($this->profile_photo_path),
            'birthday' => $this->birthday?->toDateString(),
            'status' => $this->status,
            // Same shape as meta.account on a refused request (M 2.4).
            'account' => AccountRestrictedException::describe($this->resource),
            'role_id' => $this->role_id,
            // Flat fields: older app builds read a string `role` key, so the
            // role is not exposed as a nested `role` object.
            'role_name' => AccountRole::tryFrom((int) $this->role_id)?->roleName() ?? $this->role?->name,
            // Derived from role_id; kept for existing clients.
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
                    // A provider can be suspended while the account stays
                    // active: they can sign in but cannot take work (M 9.6).
                    'suspended' => $this->providerProfile->suspended_at !== null,
                    'suspended_at' => $this->providerProfile->suspended_at?->toIso8601String(),
                    'suspension_reason' => $this->providerProfile->suspension_reason,
                ],
            ),
            'email_verified' => $this->hasVerifiedEmail(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
