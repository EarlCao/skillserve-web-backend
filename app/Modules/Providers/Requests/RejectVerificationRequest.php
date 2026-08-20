<?php

namespace App\Modules\Providers\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates a request to reject a provider's verification.
 */
class RejectVerificationRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Please provide a reason for the rejection.',
            'reason.max' => 'The rejection reason must not exceed 1000 characters.',
        ];
    }
}
