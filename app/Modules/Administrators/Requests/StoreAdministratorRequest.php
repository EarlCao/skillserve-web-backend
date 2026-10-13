<?php

namespace App\Modules\Administrators\Requests;

use App\Shared\Enums\AccountRole;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a request to create a new administrator account.
 */
class StoreAdministratorRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            // Every new administrator is an "admin": SkillServe has exactly one
            // super administrator. The field is optional and may only say so.
            'role' => ['sometimes', 'nullable', 'string', Rule::in([AccountRole::Admin->roleName()])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An administrator with this email already exists.',
            'role.in' => 'New administrators always get the admin role.',
        ];
    }
}
