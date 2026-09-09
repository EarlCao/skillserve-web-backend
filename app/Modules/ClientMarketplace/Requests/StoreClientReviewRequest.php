<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

class StoreClientReviewRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
