<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Calling off a job the provider already accepted. Unlike declining a
 * request, the customer was counting on it, so they are owed a reason.
 */
class CancelProviderBookingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Tell the customer why you are cancelling.',
            'reason.min' => 'Tell the customer why you are cancelling in at least 5 characters.',
        ];
    }
}
