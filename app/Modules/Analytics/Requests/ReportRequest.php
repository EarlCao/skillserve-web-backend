<?php

namespace App\Modules\Analytics\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends BaseFormRequest
{
    public const TYPES = ['users', 'providers', 'services', 'bookings', 'reviews', 'activity'];

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(self::TYPES)],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['created_at', 'id'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'The selected report type is not supported.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
