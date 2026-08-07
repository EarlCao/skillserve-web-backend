<?php

namespace App\Modules\Administrators\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates a request to reset an administrator's password. Unlike the
 * self-service change-password flow, no current password is required — the
 * caller is authorized through the AdministratorPolicy instead.
 */
class ResetAdministratorPasswordRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Please provide a new password.',
            'password.min' => 'The new password must be at least 8 characters.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}
