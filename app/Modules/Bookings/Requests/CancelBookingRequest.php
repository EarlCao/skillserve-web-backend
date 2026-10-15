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
            // Both parties are told why an administrator called the booking off.
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Please give a reason. The customer and the provider are told what you write.',
            'reason.max' => 'The cancellation reason must not exceed 1000 characters.',
        ];
    }
}
