<?php

namespace App\Modules\Bookings\Requests;

use App\Shared\Requests\BaseFormRequest;

class CloseDisputeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return ['note' => ['sometimes', 'nullable', 'string', 'regex:/\S/', 'max:2000']];
    }
}
