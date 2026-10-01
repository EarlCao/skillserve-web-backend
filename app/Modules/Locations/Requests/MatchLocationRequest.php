<?php

namespace App\Modules\Locations\Requests;

use App\Shared\Requests\BaseFormRequest;

class MatchLocationRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'address' => ['required', 'string', 'max:300'],
        ];
    }
}
