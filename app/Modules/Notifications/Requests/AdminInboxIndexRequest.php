<?php

namespace App\Modules\Notifications\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class AdminInboxIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['all', 'unread'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
