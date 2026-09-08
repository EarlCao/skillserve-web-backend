<?php

namespace App\Modules\Support\Requests;

use App\Shared\Requests\BaseFormRequest;

class ResolveSupportTicketRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
