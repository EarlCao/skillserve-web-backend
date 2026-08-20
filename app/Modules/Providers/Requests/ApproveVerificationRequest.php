<?php

namespace App\Modules\Providers\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates a request to approve a provider's verification.
 */
class ApproveVerificationRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.max' => 'The notes must not exceed 1000 characters.',
        ];
    }
}
