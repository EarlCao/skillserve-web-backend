<?php

namespace App\Modules\Users\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a request to update a platform user's profile.
 *
 * Editable fields only — status, roles and credentials are never changed
 * through this endpoint (dedicated suspend/activate/ban endpoints exist).
 */
class UpdateUserRequest extends BaseFormRequest
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
                Rule::unique('users', 'email')->ignore($this->route('user')?->id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'birthday' => ['sometimes', 'nullable', 'date', 'before:today'],
            'user_type' => ['sometimes', 'string', Rule::in(['customer'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'A user with this email already exists.',
            'birthday.before' => 'The birthday must be a date in the past.',
            'user_type.in' => 'The selected user type is not supported.',
        ];
    }
}
