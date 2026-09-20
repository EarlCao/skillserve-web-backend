<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Shared\Resources\BaseResource;

/**
 * A provider's work sample, as the mobile gallery consumes it.
 *
 * `portfolio_id` and `provider_id` keep the key names the app's
 * PortfolioModel already reads.
 */
class ClientPortfolioItemResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'portfolio_id' => $this->id,
            'provider_id' => $this->provider_profile_id,
            'title' => $this->title,
            'description' => $this->description,
            'image' => $this->imageUrl(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
