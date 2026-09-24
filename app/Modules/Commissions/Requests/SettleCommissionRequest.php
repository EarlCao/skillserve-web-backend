<?php

namespace App\Modules\Commissions\Requests;

use App\Modules\Commissions\Models\CommissionSettlement;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Records how SkillServe received its share. The amount is never taken from
 * the client — it is always the commission snapshotted on the booking.
 */
class SettleCommissionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(CommissionSettlement::METHODS)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
