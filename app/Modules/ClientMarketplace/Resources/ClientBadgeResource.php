<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Shared\Resources\BaseResource;
use Illuminate\Support\Carbon;

/**
 * A recognition badge as the mobile app shows it.
 *
 * `earned` is true when the badge is attached to the provider being
 * rendered; the provider's own Badges screen also lists the badges it has
 * not earned yet, which is why this is not simply a list of assignments.
 */
class ClientBadgeResource extends BaseResource
{
    public function toArray($request): array
    {
        // Present only when the badge was loaded through a provider's
        // assignments; the catalogue of unearned badges carries no pivot.
        $assignedAt = $this->resource->pivot?->assigned_at;

        return [
            'key' => $this->slug,
            'title' => $this->name,
            'criteria' => $this->description,
            'color' => $this->color,
            'earned' => $this->resource->pivot !== null,
            // withPivot() does not cast, so the value may still be a string.
            'earned_at' => $assignedAt ? Carbon::parse($assignedAt)->toIso8601String() : null,
        ];
    }
}
