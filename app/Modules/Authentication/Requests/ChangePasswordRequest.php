<?php

namespace App\Modules\Authentication\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates a password change for an authenticated administrator.
 */
class ChangePasswordRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }
}
