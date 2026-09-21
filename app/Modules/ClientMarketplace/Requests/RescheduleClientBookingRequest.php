<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

class RescheduleClientBookingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'scheduled_date' => ['required', 'date', 'after:now'],
            // When omitted, the service duration determines the end time.
            'scheduled_end_date' => ['sometimes', 'nullable', 'date', 'after:scheduled_date'],
        ];
    }
}
