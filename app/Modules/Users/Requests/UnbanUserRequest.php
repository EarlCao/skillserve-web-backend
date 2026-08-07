<?php

namespace App\Modules\Users\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates a request to lift a ban from a user account.
 */
class UnbanUserRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.max' => 'The unban note must not exceed 500 characters.',
        ];
    }
}
