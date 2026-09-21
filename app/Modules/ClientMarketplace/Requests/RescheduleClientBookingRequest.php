<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Helpers\BusinessTime;
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

    /**
     * Store UTC: a time with an offset keeps its instant; one without is read
     * as business-time wall clock (see BusinessTime).
     */
    protected function passedValidation(): void
    {
        $data = $this->validator->getData();
        foreach (['scheduled_date', 'scheduled_end_date'] as $field) {
            if (filled($data[$field] ?? null)) {
                $data[$field] = BusinessTime::toUtc((string) $data[$field])->toIso8601String();
            }
        }
        // validated() reads the validator's data, not the request's.
        $this->validator->setData($data);
    }
}
