<?php

namespace App\Modules\ClientPreferences\Requests;

use App\Modules\ClientPreferences\Models\ClientPreference;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * A partial update of the signed-in account's settings: every field is
 * optional, and only what is sent changes.
 */
class UpdateClientPreferencesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $boolean = ['sometimes', 'boolean'];

        return [
            'booking_notifications' => $boolean,
            'service_notifications' => $boolean,
            'message_notifications' => $boolean,
            'announcement_notifications' => $boolean,
            'private_profile' => $boolean,
            'activity_personalization' => $boolean,
            'reduce_motion' => $boolean,
            'theme' => ['sometimes', Rule::in(ClientPreference::THEMES)],
        ];
    }

    public function messages(): array
    {
        return [
            'theme.in' => 'The theme must be light, dark or system.',
        ];
    }
}
