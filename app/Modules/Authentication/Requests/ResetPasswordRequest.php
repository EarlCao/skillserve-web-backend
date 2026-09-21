<?php

namespace App\Modules\Authentication\Requests;

use App\Shared\Requests\BaseFormRequest;

class ResetPasswordRequest extends BaseFormRequest
{
    /**
     * Same password rule as ChangePasswordRequest.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }
}
