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
            // Providers the administrators have highlighted; drives the
            // home screen's featured rail.
            'featured' => ['sometimes', 'nullable', 'boolean'],
            // Discovery filters: the lowest star rating a result may carry,
            // and (providers only) whether the provider has a service that
            // can actually be booked online right now.
            'min_rating' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:5'],
            'available' => ['sometimes', 'nullable', 'boolean'],
            // Providers who publish hours on this weekday (0 = Sunday).
            'available_day' => ['sometimes', 'nullable', 'integer', 'between:0,6'],
            'sort' => ['sometimes', 'nullable', Rule::in(['created_at', 'title', 'price', 'average_rating', 'business_name'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
