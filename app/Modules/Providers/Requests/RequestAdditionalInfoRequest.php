<?php

namespace App\Modules\Providers\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates a request to ask a provider for additional information.
 */
class RequestAdditionalInfoRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Please specify what additional information is required.',
            'message.max' => 'The message must not exceed 2000 characters.',
        ];
    }
}
