<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ClientBookingIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(['pending', 'confirmed', 'active', 'completed', 'cancelled', 'disputed'])],
            'sort' => ['sometimes', Rule::in(['created_at', 'scheduled_date', 'status', 'total_price'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
