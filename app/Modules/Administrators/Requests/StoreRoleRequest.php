<?php

namespace App\Modules\Administrators\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a request to create a new role.
 */
class StoreRoleRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A role with this name already exists.',
            'permissions.*.exists' => 'One or more selected permissions do not exist.',
        ];
    }
}
