<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class CatalogIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:service_categories,id'],
            'subcategory_id' => ['sometimes', 'nullable', 'integer', 'exists:service_subcategories,id'],
            'provider_id' => ['sometimes', 'nullable', 'integer', 'exists:provider_profiles,id'],
            'sort' => ['sometimes', 'nullable', Rule::in(['created_at', 'title', 'price', 'average_rating', 'business_name'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
