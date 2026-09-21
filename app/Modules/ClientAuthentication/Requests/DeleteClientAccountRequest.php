<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Shared\Requests\BaseFormRequest;

class DeleteClientAccountRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Closing an account is irreversible from the app's side, so it is
            // confirmed with the password rather than a tap alone.
            'password' => ['required', 'string'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
