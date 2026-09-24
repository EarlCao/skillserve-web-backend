<?php

namespace App\Modules\Commissions\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * Field-level validation for a new commission band. Whether the band overlaps
 * an existing one is decided by CommissionTierService inside a locked
 * transaction — it is a cross-row rule and cannot be checked safely here.
 */
class StoreCommissionTierRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'min_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            // Omitted or null makes this the open-ended top band.
            'max_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'gte:min_amount'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'max_amount.gte' => 'The maximum amount must be greater than or equal to the minimum amount.',
            'percentage.max' => 'The commission percentage cannot be more than 100%.',
        ];
    }

    public function attributes(): array
    {
        return [
            'min_amount' => 'minimum amount',
            'max_amount' => 'maximum amount',
            'percentage' => 'commission percentage',
        ];
    }
}
