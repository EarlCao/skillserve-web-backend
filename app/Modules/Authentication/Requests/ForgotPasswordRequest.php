<?php

namespace App\Modules\Authentication\Requests;

use App\Shared\Requests\BaseFormRequest;

class ForgotPasswordRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
