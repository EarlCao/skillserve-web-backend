<?php

namespace App\Modules\Commissions\Requests;

use App\Shared\Requests\BaseFormRequest;

class ConfirmCommissionRemittanceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
