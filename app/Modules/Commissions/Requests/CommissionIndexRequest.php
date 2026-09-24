<?php

namespace App\Modules\Commissions\Requests;

use App\Modules\Commissions\Services\CommissionLedger;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class CommissionIndexRequest extends BaseFormRequest
{
    /** @var array<int, string> */
    public const STATUSES = [
        CommissionLedger::PENDING,
        CommissionLedger::OUTSTANDING,
        CommissionLedger::SETTLED,
        CommissionLedger::WAIVED,
        CommissionLedger::VOIDED,
    ];

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(self::STATUSES)],
            'provider_id' => ['sometimes', 'nullable', 'integer', 'exists:provider_profiles,id'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'sort' => ['sometimes', 'nullable', Rule::in(['paid_at', 'platform_fee', 'created_at'])],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
