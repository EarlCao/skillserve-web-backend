<?php

namespace App\Modules\Commissions\Requests;

use App\Modules\Commissions\Models\CommissionRemittance;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class CommissionRemittanceIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(CommissionRemittance::STATUSES)],
            'provider_id' => ['sometimes', 'nullable', 'integer', 'exists:provider_profiles,id'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
