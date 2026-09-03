<?php

namespace App\Modules\Reviews\Requests;

use App\Shared\Requests\BaseFormRequest;

class HideReviewRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'is_hidden' => ['required', 'boolean'],
        ];
    }
}
