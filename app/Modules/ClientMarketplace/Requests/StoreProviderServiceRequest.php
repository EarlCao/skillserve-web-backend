<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreProviderServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => ['required', Rule::exists('service_categories', 'id')->where('status', 'enabled')],
            'subcategory_id' => [
                'sometimes',
                'nullable',
                Rule::exists('service_subcategories', 'id')
                    ->where(fn ($query) => $query->where('category_id', $this->input('category_id'))->where('status', 'enabled')),
            ],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'price_type' => ['required', 'string', Rule::in(['fixed', 'hourly', 'custom'])],
            'duration' => ['sometimes', 'nullable', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Please provide a title for the service.',
            'category_id.required' => 'Please select a category for the service.',
            'category_id.exists' => 'The selected category is not available.',
            'subcategory_id.exists' => 'The selected subcategory is not available for this category.',
            'price.required' => 'Please set a price for the service.',
        ];
    }
}
