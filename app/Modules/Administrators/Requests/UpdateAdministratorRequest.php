<?php

namespace App\Modules\Administrators\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a request to update an existing administrator account.
 *
 * Passwords are never handled here — the module deliberately does not
 * overwrite passwords (no change-password flow for administrators yet).
 */
class UpdateAdministratorRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('administrator')?->id),
            ],
            'role' => ['sometimes', 'string', Rule::exists('roles', 'name')],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An administrator with this email already exists.',
            'role.exists' => 'The selected role does not exist.',
        ];
    }
}
