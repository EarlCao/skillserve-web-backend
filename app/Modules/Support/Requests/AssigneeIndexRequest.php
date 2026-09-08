<?php

namespace App\Modules\Support\Requests;

use App\Shared\Requests\BaseFormRequest;

class AssigneeIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
