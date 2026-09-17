<?php

namespace App\Modules\ClientMarketplace\Resources;

/**
 * A provider's own service, including its moderation state.
 */
class ProviderServiceResource extends ClientServiceResource
{
    public function toArray($request): array
    {
        return array_merge(parent::toArray($request), [
            'category_id' => $this->category_id,
            'subcategory_id' => $this->subcategory_id,
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'rejection_reason' => $this->rejection_reason,
            'is_featured' => (bool) $this->is_featured,
            'is_hidden' => (bool) $this->is_hidden,
            'approved_at' => $this->approved_at?->toIso8601String(),
        ]);
    }
}
