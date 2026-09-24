<?php

namespace App\Modules\Commissions\Requests;

use App\Shared\Requests\BaseFormRequest;

/**
 * A partial update of a commission band. Bounds are validated against the
 * values the band will actually end up with, so sending only `max_amount`
 * is still checked against the stored `min_amount`.
 */
class UpdateCommissionTierRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'min_amount' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'max_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'percentage' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * `gte:min_amount` cannot be used here: on a partial update the other
     * bound may be absent from the payload, so compare against the merged
     * result instead.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $tier = $this->route('commissionTier');

            $min = $this->has('min_amount') ? (float) $this->input('min_amount') : (float) $tier->min_amount;

            $max = $this->has('max_amount')
                ? ($this->input('max_amount') === null ? null : (float) $this->input('max_amount'))
                : ($tier->max_amount === null ? null : (float) $tier->max_amount);

            if ($max !== null && $max < $min) {
                $validator->errors()->add(
                    'max_amount',
                    'The maximum amount must be greater than or equal to the minimum amount.',
                );
            }
        });
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
