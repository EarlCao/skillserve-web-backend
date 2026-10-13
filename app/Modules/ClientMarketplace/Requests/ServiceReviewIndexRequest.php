<?php

namespace App\Modules\ClientMarketplace\Requests;

use App\Shared\Requests\BaseFormRequest;

class ServiceReviewIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Only reviews with this many stars.
            'rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
