<?php

namespace App\Modules\DataManagement\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class IndexDataRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'resource_type' => ['nullable', Rule::in(config('data-management.resource_types'))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
