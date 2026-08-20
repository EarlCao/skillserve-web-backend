<?php

namespace App\Modules\Services\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a request to create a new service.
 */
class StoreServiceRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => ['required', 'exists:service_categories,id'],
            'subcategory_id' => ['sometimes', 'nullable', 'exists:service_subcategories,id'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_type' => ['sometimes', 'string', Rule::in(['fixed', 'hourly', 'custom'])],
            'currency' => ['sometimes', 'string', 'max:3'],
            'duration' => ['sometimes', 'nullable', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Please provide a title for the service.',
            'category_id.required' => 'Please select a category for the service.',
            'category_id.exists' => 'The selected category does not exist.',
            'subcategory_id.exists' => 'The selected subcategory does not exist.',
        ];
    }
}
