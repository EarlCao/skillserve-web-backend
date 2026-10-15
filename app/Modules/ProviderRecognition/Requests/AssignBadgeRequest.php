<?php

namespace App\Modules\ProviderRecognition\Requests;

use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class AssignBadgeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // A note passed on to the provider with the badge.
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'badge_id' => [
                'required',
                'integer',
                Rule::exists('provider_badges', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ];
    }
}
