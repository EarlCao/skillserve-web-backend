<?php

namespace App\Modules\Bookings\Requests;

use App\Shared\Requests\BaseFormRequest;

class ResolveDisputeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return ['resolution' => ['required', 'string', 'regex:/\S/', 'max:2000']];
    }
}
