<?php

namespace App\Modules\ClientMarketplace\Resources;

/**
 * The signed-in provider's own profile, including verification state that
 * the public catalog does not expose.
 */
class ProviderProfileResource extends ClientProviderResource
{
    public function toArray($request): array
    {
        return array_merge(parent::toArray($request), [
            'user_id' => $this->user_id,
            'verification_status' => $this->verification_status,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'is_featured' => (bool) $this->is_featured,
            'is_suspended' => $this->suspended_at !== null,
        ]);
    }
}
