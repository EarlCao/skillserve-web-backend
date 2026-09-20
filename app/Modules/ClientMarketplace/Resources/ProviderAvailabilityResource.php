<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Shared\Resources\BaseResource;

/**
 * One weekday window of a provider's published schedule.
 *
 * `day` is included so the app can render the schedule without mapping
 * numbers to day names itself.
 */
class ProviderAvailabilityResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'day_of_week' => (int) $this->day_of_week,
            'day' => $this->resource->dayName(),
            'start_time' => $this->resource->startsAt(),
            'end_time' => $this->resource->endsAt(),
        ];
    }
}
