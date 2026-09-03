<?php

namespace App\Modules\Bookings\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ManageDisputeRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['investigate', 'resolve', 'reject'])],
            'resolution' => ['required_if:action,resolve', 'sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.required' => 'Please specify the dispute management action.',
            'action.in' => 'The action must be investigate, resolve, or reject.',
            'resolution.required_if' => 'Please provide a resolution when resolving a dispute.',
        ];
    }
}
