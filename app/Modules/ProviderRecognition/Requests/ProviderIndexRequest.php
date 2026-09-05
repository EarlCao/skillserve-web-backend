<?php

namespace App\Modules\ProviderRecognition\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ProviderIndexRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('is_featured')) {
            $value = $this->input('is_featured');

            if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
                $this->merge(['is_featured' => strtolower($value) === 'true']);
            }
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'badge_id' => ['nullable', 'integer', Rule::exists('provider_badges', 'id')],
            'is_featured' => ['nullable', 'boolean'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'sort' => ['nullable', Rule::in(['created_at', 'average_rating', 'total_bookings', 'total_reviews'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
