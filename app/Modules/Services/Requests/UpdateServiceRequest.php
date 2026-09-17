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
            // Owned by the provider (set through the client API); administrators
            // moderate listings but never change the provider's offer.
            'provider_id' => ['prohibited'],
            'price' => ['prohibited'],
            'price_type' => ['prohibited'],
            'currency' => ['prohibited'],
            'duration' => ['prohibited'],
            'location' => ['prohibited'],
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
            'provider_id.prohibited' => 'Only the provider can change who offers this service.',
            'price.prohibited' => 'Only the provider can change the price.',
            'price_type.prohibited' => 'Only the provider can change the price type.',
            'currency.prohibited' => 'Only the provider can change the currency.',
            'duration.prohibited' => 'Only the provider can change the duration.',
            'location.prohibited' => 'Only the provider can change the location.',
        ];
    }
}
