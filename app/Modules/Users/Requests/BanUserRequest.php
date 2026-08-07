<?php

namespace App\Modules\Users\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates a request to permanently ban a user account.
 */
class BanUserRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Please provide a reason for the ban.',
            'reason.max' => 'The ban reason must not exceed 500 characters.',
        ];
    }
}
