<?php

namespace App\Modules\Support\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class SupportTicketIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::in(['open', 'in_progress', 'resolved'])],
            'priority' => ['sometimes', 'nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(['created_at', 'updated_at', 'priority', 'status'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
