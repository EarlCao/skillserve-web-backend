<?php

namespace App\Modules\ClientAuthentication\Requests;

use App\Shared\Requests\BaseFormRequest;

class ForgotClientPasswordRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
