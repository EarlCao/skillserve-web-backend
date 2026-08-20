<?php

namespace App\Modules\Services\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates a request to reject a service.
 */
class RejectServiceRequest extends BaseFormRequest
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
            'reason.required' => 'Please provide a reason for rejecting this service.',
        ];
    }
}
