<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Shared\Requests\BaseFormRequest;

class GoogleClientAuthRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'min:20'],
        ];
    }
}
