<?php

namespace App\Modules\Notifications\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class RecipientsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'target' => ['required', Rule::in(['all', 'customers', 'providers', 'selected'])],
            'search' => ['nullable', 'string', 'max:160'],
        ];
    }
}
