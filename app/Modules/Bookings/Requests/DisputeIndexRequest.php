<?php

namespace App\Modules\Bookings\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class DisputeIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::in(['pending', 'investigated', 'resolved', 'rejected', 'closed'])],
            'provider_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'client_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'service_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['sometimes', Rule::in(['booking_number', 'created_at', 'disputed_at', 'dispute_status'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
