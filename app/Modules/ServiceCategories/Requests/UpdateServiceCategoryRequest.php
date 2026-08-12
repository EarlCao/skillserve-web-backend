<?php

namespace App\Modules\ServiceCategories\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a request to update a service category.
 *
 * All fields are optional (`sometimes`) so a partial PATCH works; status is
 * normally changed through the dedicated /status endpoint.
 */
class UpdateServiceCategoryRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('service_categories', 'name')->ignore($this->route('serviceCategory')?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'string', Rule::in(['enabled', 'disabled'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A service category with this name already exists.',
        ];
    }
}
