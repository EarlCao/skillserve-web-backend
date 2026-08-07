<?php

namespace App\Modules\Authentication\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates the administrator login payload.
 */
class LoginRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
