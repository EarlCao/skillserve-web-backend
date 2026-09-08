<?php

namespace App\Modules\Services\Requests;

use App\Modules\Services\Models\Service;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a request to update an existing service.
 */
class UpdateServiceRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $service = $this->route('service');
        $categoryId = $this->input('category_id') ?? ($service instanceof Service ? $service->category_id : null);

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => ['sometimes', 'exists:service_categories,id'],
            'subcategory_id' => [
                'sometimes',
                'nullable',
                Rule::exists('service_subcategories', 'id')
                    ->where(fn ($query) => $query->where('category_id', $categoryId)),
            ],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_type' => ['sometimes', 'string', Rule::in(['fixed', 'hourly', 'custom'])],
            'currency' => ['sometimes', 'string', 'max:3'],
            'duration' => ['sometimes', 'nullable', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'archived'])],
            'is_featured' => ['sometimes', 'boolean'],
            'is_hidden' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected category does not exist.',
            'subcategory_id.exists' => 'The selected subcategory does not exist.',
        ];
    }
}
