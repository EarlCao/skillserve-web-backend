<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Shared\Requests\BaseFormRequest;

class GoogleClientAuthRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'min:20'],
            // The account password; without it an existing account answers `password_required`.
            'password' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
