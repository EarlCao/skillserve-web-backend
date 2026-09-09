<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Shared\Requests\BaseFormRequest;

class ChangeClientPasswordRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }
}
