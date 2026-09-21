<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class BookingDisputeIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'dispute_status' => ['sometimes', 'nullable', Rule::in(['pending', 'investigated', 'resolved', 'rejected', 'closed'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
