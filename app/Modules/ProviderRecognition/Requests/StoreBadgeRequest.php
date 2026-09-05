<?php

namespace App\Modules\ProviderRecognition\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreBadgeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', Rule::unique('provider_badges', 'slug')],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
