<?php

namespace App\Modules\ServiceCategories\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a request to create a subcategory under a specific category.
 *
 * The name must be unique within the parent category (never globally).
 */
class StoreServiceSubcategoryRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('service_subcategories', 'name')->where(
                    'category_id',
                    $this->route('serviceCategory')?->id,
                ),
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
            'name.unique' => 'A subcategory with this name already exists in this category.',
        ];
    }
}
