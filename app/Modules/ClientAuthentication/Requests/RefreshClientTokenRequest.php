<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Shared\Requests\BaseFormRequest;

class RefreshClientTokenRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string', 'min:32'],
        ];
    }
}
