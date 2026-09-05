<?php

namespace App\Modules\Notifications\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class IndexNotificationRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::in(['pending', 'scheduled', 'sent', 'failed'])],
            'target' => ['nullable', Rule::in(['all', 'customers', 'providers', 'selected'])],
            'sort' => ['nullable', Rule::in(['created_at', 'scheduled_at', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
