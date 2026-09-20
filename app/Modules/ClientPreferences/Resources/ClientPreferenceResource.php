<?php

namespace App\Modules\ClientPreferences\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'booking_notifications' => $this->booking_notifications,
            'service_notifications' => $this->service_notifications,
            'message_notifications' => $this->message_notifications,
            'announcement_notifications' => $this->announcement_notifications,
            'private_profile' => $this->private_profile,
            'activity_personalization' => $this->activity_personalization,
            'reduce_motion' => $this->reduce_motion,
            'theme' => $this->theme,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
