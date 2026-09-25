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
            // is_featured comes from the parent resource.
            'is_suspended' => $this->suspended_at !== null,

            // Where customers send payment. Only ever on the provider's OWN
            // profile — ClientProviderResource, which the public catalog uses,
            // deliberately does not carry these.
            'gcash_number' => $this->gcash_number,
            'gcash_name' => $this->gcash_name,
            // Whether this provider can actually be paid by GCash yet.
            'can_receive_gcash' => $this->canReceiveGcash(),
        ]);
    }
}
