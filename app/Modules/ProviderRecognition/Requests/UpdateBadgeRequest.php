<?php

namespace App\Modules\ProviderRecognition\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateBadgeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:120', 'alpha_dash', Rule::unique('provider_badges', 'slug')->ignore($this->route('badge'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
