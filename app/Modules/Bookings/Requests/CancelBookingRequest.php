<?php

namespace App\Modules\Bookings\Requests;

use App\Shared\Requests\BaseFormRequest;

class CancelBookingRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.max' => 'The cancellation reason must not exceed 1000 characters.',
        ];
    }
}
