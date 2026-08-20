<?php

namespace App\Modules\Providers\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates a request to suspend a service provider.
 */
class SuspendProviderRequest extends BaseFormRequest
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
            'reason.required' => 'Please provide a reason for the suspension.',
            'reason.max' => 'The suspension reason must not exceed 500 characters.',
        ];
    }
}
