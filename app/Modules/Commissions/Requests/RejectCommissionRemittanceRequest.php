<?php

namespace App\Modules\Commissions\Requests;

use App\Shared\Requests\BaseFormRequest;

class RejectCommissionRemittanceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // The provider is told this, so they know what to fix.
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
