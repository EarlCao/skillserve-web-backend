<?php

namespace App\Modules\Bookings\Requests;

use App\Shared\Requests\BaseFormRequest;

class RefundBookingRequest extends BaseFormRequest
{
    /**
     * The upper bound depends on what is left to refund, which only the
     * locked booking knows, so BookingPaymentService checks it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.decimal' => 'The refund amount can have at most two decimal places.',
            'reason.required' => 'Record why the refund was made.',
        ];
    }
}
