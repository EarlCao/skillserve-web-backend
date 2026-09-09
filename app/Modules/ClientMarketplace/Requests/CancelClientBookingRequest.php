<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

class CancelClientBookingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
