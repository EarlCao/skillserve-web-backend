<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Shared\Requests\BaseFormRequest;

class ResetClientPasswordRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }
}
