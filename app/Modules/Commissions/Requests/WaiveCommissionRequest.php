<?php

namespace App\Modules\Commissions\Requests;

use App\Shared\Requests\BaseFormRequest;

class WaiveCommissionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Writing off platform revenue always needs a stated reason.
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
