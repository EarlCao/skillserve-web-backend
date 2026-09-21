<?php

namespace App\Modules\Bookings\Requests;

use App\Shared\Requests\BaseFormRequest;

class MarkBookingPaidRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // e.g. a GCash or bank transfer reference number.
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
