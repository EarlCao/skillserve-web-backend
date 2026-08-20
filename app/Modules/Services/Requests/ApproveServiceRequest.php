<?php

namespace App\Modules\Services\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Validates a request to approve a service.
 */
class ApproveServiceRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
