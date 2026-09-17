<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Modules\Services\Models\Service;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateProviderServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $service = $this->route('service');
        $categoryId = $this->input('category_id') ?? ($service instanceof Service ? $service->category_id : null);

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => ['sometimes', Rule::exists('service_categories', 'id')->where('status', 'enabled')],
            'subcategory_id' => [
                'sometimes',
                'nullable',
                Rule::exists('service_subcategories', 'id')
                    ->where(fn ($query) => $query->where('category_id', $categoryId)->where('status', 'enabled')),
            ],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'price_type' => ['sometimes', 'string', Rule::in(['fixed', 'hourly', 'custom'])],
            'duration' => ['sometimes', 'nullable', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected category is not available.',
            'subcategory_id.exists' => 'The selected subcategory is not available for this category.',
        ];
    }
}
